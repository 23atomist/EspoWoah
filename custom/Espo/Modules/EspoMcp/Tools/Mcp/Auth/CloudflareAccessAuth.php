<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Licensed under the MIT License.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Tools\Mcp\Auth;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\Entities\User;
use RuntimeException;

/**
 * Verifies Cloudflare Access JWTs (CF_Authorization cookie / Cf-Access-Jwt-Assertion
 * header) against the team's public keys and resolves the asserted email
 * to an EspoCRM user.
 *
 * This lets the MCP endpoint authenticate with the exact same identity
 * used to sign into the CRM through Cloudflare Access (Stalwart OIDC).
 */
class CloudflareAccessAuth
{
    private const string JWT_HEADER = 'Cf-Access-Jwt-Assertion';
    private const string JWT_COOKIE = 'CF_Authorization';

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('mcp.cloudflareAccess.enabled');
    }

    public function espoAuthFallbackDisabled(): bool
    {
        return (bool) $this->config->get('mcp.cloudflareAccess.disableEspoAuthFallback');
    }

    /**
     * @return ?string the raw JWT from the request, null if absent.
     */
    public function obtainJwt(array $headers, array $cookies): ?string
    {
        $headerValue = $headers[self::JWT_HEADER] ?? null;

        if (is_string($headerValue) && $headerValue !== '') {
            return $headerValue;
        }

        $cookieValue = $cookies[self::JWT_COOKIE] ?? null;

        if (is_string($cookieValue) && $cookieValue !== '') {
            return $cookieValue;
        }

        return null;
    }

    /**
     * Verify the JWT and return the asserted email.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @return ?string email if verification succeeds, null otherwise.
     */
    public function verifyAndGetEmail(array $headers, array $cookies): ?string
    {
        $jwt = $this->obtainJwt($headers, $cookies);

        if (!$jwt) {
            return null;
        }

        try {
            $claims = $this->decodeAndVerify($jwt);

            return $claims['email'] ?? null;
        } catch (RuntimeException $e) {
            $this->log->warning("MCP Cloudflare Access verification failed: " . $e->getMessage());

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAndVerify(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new RuntimeException("Malformed JWT.");
        }

        [$headerRaw, $payloadRaw, $signatureRaw] = $parts;

        $header = $this->base64UrlDecodeJson($headerRaw);
        $payload = $this->base64UrlDecodeJson($payloadRaw);

        $alg = $header['alg'] ?? null;
        $kid = $header['kid'] ?? null;

        if (!$alg || !$kid) {
            throw new RuntimeException("Missing alg or kid in JWT header.");
        }

        $publicKey = $this->fetchPublicKey($kid, $alg);

        $signature = self::base64UrlDecode($signatureRaw);

        $signedContent = $headerRaw . '.' . $payloadRaw;

        $pem = $publicKey['pem'];

        $ok = match ($alg) {
            'RS256' => openssl_verify($signedContent, $signature, $pem, OPENSSL_ALGO_SHA256) === 1,
            'ES256' => openssl_verify($signedContent, $this->signatureAsn1($signature), $pem, OPENSSL_ALGO_SHA256) === 1,
            default => throw new RuntimeException("Unsupported algorithm '$alg'."),
        };

        if (!$ok) {
            throw new RuntimeException("Signature verification failed.");
        }

        $aud = $this->getAudience();
        $teamName = $this->getTeamName();

        $now = time();
        $exp = $payload['exp'] ?? 0;
        $iat = $payload['iat'] ?? 0;

        if ($exp < $now) {
            throw new RuntimeException("Token expired.");
        }

        if ($iat - 300 > $now) {
            throw new RuntimeException("Token issued in the future.");
        }

        $payloadAud = $payload['aud'] ?? null;

        $audMatch = is_string($aud) ? $payloadAud === $aud : false;

        if (is_array($payloadAud) && $aud !== null) {
            $audMatch = in_array($aud, $payloadAud, true);
        }

        if (!$audMatch) {
            throw new RuntimeException("Audience mismatch.");
        }

        $iss = $payload['iss'] ?? '';

        if ($teamName && $iss !== "https://$teamName.cloudflareaccess.com") {
            throw new RuntimeException("Issuer mismatch.");
        }

        $email = $payload['email'] ?? null;

        if (!is_string($email) || $email === '') {
            // Cloudflare Access service tokens assert `common_name` and carry
            // no email - they identify a machine, not a person. Map a known
            // token to an EspoCRM user so headless clients act as a real,
            // scoped identity instead of impersonating a human.
            $email = $this->resolveServiceTokenEmail($payload);
        }

        if (!is_string($email) || $email === '') {
            throw new RuntimeException("No email claim in token.");
        }

        if (!$this->isEmailAllowed($email)) {
            throw new RuntimeException("Email '$email' is not allowed.");
        }

        // Downstream resolves the user from this claim; a service token has
        // none of its own, so the mapped address is written back here.
        $payload['email'] = $email;

        return $payload;
    }

    /**
     * Map a Cloudflare Access service token to an EspoCRM user's email.
     *
     * Configured as mcp.cloudflareAccess.serviceTokens:
     *   [ '<common_name>' => 'mcp@example.com' ]
     *
     * The mapped address must still satisfy the email allow-list, so a stray
     * mapping cannot widen access beyond what emailDomains permits.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveServiceTokenEmail(array $payload): ?string
    {
        $commonName = $payload['common_name'] ?? null;

        if (!is_string($commonName) || $commonName === '') {
            return null;
        }

        $map = $this->config->get('mcp.cloudflareAccess.serviceTokens');

        if (!is_array($map)) {
            return null;
        }

        $email = $map[$commonName] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * @return array{pem: string}
     */
    private function fetchPublicKey(string $kid, string $alg): array
    {
        $certsUrl = (string) $this->config->get('mcp.cloudflareAccess.certsUrl');

        if (!$certsUrl) {
            throw new RuntimeException("No certs URL configured (mcp.cloudflareAccess.certsUrl).");
        }

        $cacheFile = 'data/cache/mcp-cf-access-certs.json';

        $cache = null;
        $cacheTtl = 3600;

        if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheTtl) {
            $cache = json_decode((string) file_get_contents($cacheFile), true);
        }

        if (!$cache) {
            $contents = $this->httpGet($certsUrl);

            if ($contents === null) {
                throw new RuntimeException("Could not fetch Cloudflare Access public keys.");
            }

            $cache = json_decode($contents, true);

            if (!is_array($cache)) {
                throw new RuntimeException("Invalid certs response.");
            }

            @file_put_contents($cacheFile, $contents);
        }

        $keyEntry = null;

        // Cloudflare's certs endpoint returns BOTH representations:
        //   "keys"         - JWKS entries (kid, kty, alg, use, n, e)
        //   "public_certs" - PEM certificates (kid, cert)
        // Only the latter carry a certificate. Matching against "keys" alone
        // finds an entry with neither `cert` nor `x5c`, which then fails as
        // "no usable certificate" - looking like a key mismatch when it is
        // really the wrong half of the response.
        $candidates = [];

        if (isset($cache['public_certs']) && is_array($cache['public_certs'])) {
            $candidates = $cache['public_certs'];
        }

        if (isset($cache['keys']) && is_array($cache['keys'])) {
            $candidates = array_merge($candidates, $cache['keys']);
        }

        if ($candidates === []) {
            $candidates = (array) $cache;
        }

        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || ($candidate['kid'] ?? null) !== $kid) {
                continue;
            }

            // Prefer an entry that actually carries a certificate; keep any
            // kid match as a fallback so the failure message stays accurate.
            if (isset($candidate['cert']) || isset($candidate['x5c'])) {
                $keyEntry = $candidate;

                break;
            }

            $keyEntry ??= $candidate;
        }

        if (!$keyEntry) {
            throw new RuntimeException("No matching key for kid '$kid'.");
        }

        $pem = null;

        $cert = $keyEntry['cert'] ?? null;

        if (is_string($cert) && $cert !== '') {
            $pem = str_contains($cert, 'BEGIN CERTIFICATE')
                ? $cert
                : $this->certToPem($cert);
        }

        if ($pem === null) {
            $x5c = $keyEntry['x5c'] ?? null;

            if (is_array($x5c) && isset($x5c[0]) && is_string($x5c[0]) && $x5c[0] !== '') {
                $pem = $this->certToPem($x5c[0]);
            }
        }

        if ($pem === null) {
            throw new RuntimeException("Public key entry has no usable certificate.");
        }

        if (openssl_pkey_get_public($pem) === false) {
            throw new RuntimeException("Could not load public key.");
        }

        return ['pem' => $pem];
    }

    /**
     * Convert a raw P-256 (r, s) signature into DER, or pass through
     * if already DER-encoded.
     */
    private function signatureAsn1(string $signature): string
    {
        if (strlen($signature) === 64) {
            $r = substr($signature, 0, 32);
            $s = substr($signature, 32, 32);

            return ASN1::sequence(
                ASN1::integerRaw($r) .
                ASN1::integerRaw($s)
            );
        }

        return $signature;
    }

    private function certToPem(string $certBase64): string
    {
        if (str_contains($certBase64, 'BEGIN CERTIFICATE')) {
            return $certBase64;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n";
        $pem .= chunk_split($certBase64, 64, "\n");
        $pem .= "-----END CERTIFICATE-----\n";

        return $pem;
    }

    private function isEmailAllowed(string $email): bool
    {
        $allowed = $this->config->get('mcp.cloudflareAccess.allowedEmails');

        if (is_array($allowed) && $allowed !== []) {
            $allowed = array_map(fn ($item) => mb_strtolower((string) $item), $allowed);

            if (in_array(mb_strtolower($email), $allowed, true)) {
                return true;
            }
        }

        $domain = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));

        if ($domain === '') {
            return false;
        }

        $domainCfg = $this->config->get('mcp.cloudflareAccess.emailDomain');
        $domainsCfg = $this->config->get('mcp.cloudflareAccess.emailDomains');

        $domains = [];

        if (is_string($domainCfg) && $domainCfg !== '') {
            $domains[] = mb_strtolower($domainCfg);
        }

        if (is_array($domainsCfg)) {
            foreach ($domainsCfg as $d) {
                if (is_string($d) && $d !== '') {
                    $domains[] = mb_strtolower($d);
                }
            }
        }

        if ($domains === []) {
            return true;
        }

        return in_array($domain, $domains, true);
    }

    private function getAudience(): ?string
    {
        $aud = $this->config->get('mcp.cloudflareAccess.applicationAud');

        return is_string($aud) && $aud !== '' ? $aud : null;
    }

    private function getTeamName(): ?string
    {
        $team = $this->config->get('mcp.cloudflareAccess.teamName');

        return is_string($team) && $team !== '' ? $team : null;
    }

    /**
     * Resolve the email to an existing, active, non-API EspoCRM user.
     */
    public function resolveUserByEmail(string $email): ?User
    {
        $user = $this->entityManager
            ->getRDBRepository(User::ENTITY_TYPE)
            ->where([
                'emailAddress' => $email,
                'isActive' => true,
            ])
            ->findOne();

        return $user;
    }

    private function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => false,
            ],
        ]);

        $level = error_reporting(E_ALL & ~E_WARNING);

        try {
            $contents = file_get_contents($url, false, $context);

            return $contents === false ? null : $contents;
        } finally {
            error_reporting($level);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function base64UrlDecodeJson(string $raw): array
    {
        $json = self::base64UrlDecode($raw);

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new RuntimeException("Could not decode JWT part.");
        }

        return $data;
    }

    private static function base64UrlDecode(string $raw): string
    {
        $remainder = strlen($raw) % 4;

        if ($remainder) {
            $raw .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException("Could not base64url decode.");
        }

        return $decoded;
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}