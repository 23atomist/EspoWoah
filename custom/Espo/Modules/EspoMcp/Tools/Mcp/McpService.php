<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Licensed under the MIT License.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Tools\Mcp;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Modules\EspoMcp\Tools\Mcp\Auth\CloudflareAccessAuth;
use Espo\Modules\EspoMcp\Tools\Mcp\Setup\SetupService;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * MCP protocol service.
 *
 * Handles JSON-RPC 2.0 requests on /api/v1/mcp:
 *   - initialize, tools/list, tools/call, ping, notifications/initialized
 *
 * Identity resolution order:
 *   1. Cloudflare Access JWT (when enabled and present) — maps the asserted
 *      email to an EspoCRM user. This is the "sign in like the CRM" flow
 *      for deployments fronted by Cloudflare Access + OIDC (e.g. Stalwart).
 *   2. Standard EspoCRM API auth — the request was already authenticated
 *      by the core API middleware (Basic auth / auth token / API key).
 */
class McpService
{
    private const string PROTOCOL_VERSION = '2025-06-18';

    private const string SERVER_NAME = 'espocrm-mcp';

    private const string SERVER_VERSION = '1.0.0';

    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
        private Config $config,
        private Log $log,
        private User $user,
        private CloudflareAccessAuth $cfAccessAuth,
    ) {}

    public function processGet(Request $request): Response
    {
        return ResponseComposer::json((object) [
            'name' => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'protocolVersion' => self::PROTOCOL_VERSION,
            'endpoint' => 'POST /api/v1/mcp',
            'transport' => 'streamable-http (stateless)',
            'identitySource' => $this->cfAccessAuth->isEnabled() ? 'cloudflare-access | espo-auth' : 'espo-auth',
            'user' => (object) [
                'id' => $this->user->getId(),
                'name' => $this->user->get('name'),
                'userName' => $this->user->get('userName'),
            ],
        ]);
    }

    public function processPost(Request $request, Response $response): Response
    {
        $body = $request->getBodyContents();

        if ($body === null || $body === '') {
            return $this->jsonRpcError(null, -32600, "Empty request body.");
        }

        $decoded = Json::decode($body);

        if (!($decoded instanceof stdClass)) {
            return $this->jsonRpcError(null, -32600, "Request must be a JSON object.");
        }

        $hasId = property_exists($decoded, 'id') && $decoded->id !== null;

        if (!$hasId) {
            // A JSON-RPC notification. Per spec no response is sent.
            return $this->emptyResponse();
        }

        return $this->processSingle($decoded, $response);
    }

    private function processSingle(stdClass $data, Response $response): Response
    {
        $id = $data->id ?? null;
        $method = $data->method ?? null;
        $params = $data->params ?? null;

        if (!is_string($method)) {
            return $this->jsonRpcError($id, -32600, "Missing or invalid 'method'.");
        }

        if ($params !== null && !($params instanceof stdClass)) {
            return $this->jsonRpcError($id, -32602, "Invalid 'params'.");
        }

        return match ($method) {
            'initialize' => $this->handleInitialize($id),
            'ping' => $this->handlePing($id),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $params),
            'resources/list' => $this->handleResourcesList($id),
            'prompts/list' => $this->handlePromptsList($id),
            default => $this->jsonRpcError($id, -32601, "Method '$method' not found."),
        };
    }

    private function handleInitialize(mixed $id): Response
    {
        $user = $this->resolveUser();
        $identitySource = $this->identitySource();
        $setupRequired = $this->createSetupService()->isSetupRequired($user);

        $userPayload = [
            'id' => $user->getId(),
            'name' => $user->get('name'),
            'userName' => $user->get('userName'),
            'isAdmin' => $user->isAdmin(),
            'teams' => $user->get('teamsIds') ?? [],
        ];

        $result = (object) [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => (object) [
                'tools' => (object) ['listChanged' => false],
                'resources' => (object) ['listChanged' => false, 'subscribe' => false],
                'prompts' => (object) ['listChanged' => false],
            ],
            'serverInfo' => (object) [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
                'identitySource' => $identitySource,
                'user' => (object) $userPayload,
                'setup' => (object) [
                    'required' => $setupRequired,
                    'reason' => $setupRequired
                        ? 'admin session, no MCP service user provisioned'
                        : null,
                    'nextTool' => $setupRequired ? 'mcp_setup_status' : null,
                ],
            ],
        ];

        return $this->jsonRpcResult($id, $result);
    }

    private function handlePing(mixed $id): Response
    {
        return $this->jsonRpcResult($id, (object) []);
    }

    private function handleToolsList(mixed $id): Response
    {
        $registry = $this->injectableFactory->create(ToolRegistry::class);

        return $this->jsonRpcResult($id, (object) [
            'tools' => $registry->getAll($this->setupToolsVisible()),
        ]);
    }

    private function handleToolsCall(mixed $id, ?stdClass $params): Response
    {
        $name = $params->name ?? null;

        if (!is_string($name) || $name === '') {
            return $this->jsonRpcError($id, -32602, "Missing tool 'name'.");
        }

        $arguments = $params->arguments ?? null;

        if ($arguments === null) {
            $arguments = new stdClass();
        }

        if (!($arguments instanceof stdClass)) {
            return $this->jsonRpcError($id, -32602, "Invalid tool 'arguments'.");
        }

        $this->log->debug("MCP tools/call '{name}'.", ['name' => $name]);

        try {
            $result = $this->executeTool($name, $arguments);

            $textResult = Json::encode($result);

            return $this->jsonRpcResult($id, (object) [
                'content' => [
                    (object) ['type' => 'text', 'text' => $textResult],
                ],
                'isError' => false,
            ]);
        } catch (BadRequest|NotFound|Forbidden|Error $e) {
            return $this->jsonRpcResult($id, (object) [
                'content' => [
                    (object) [
                        'type' => 'text',
                        'text' => $e->getMessage(),
                    ],
                ],
                'isError' => true,
            ]);
        } catch (Throwable $e) {
            $this->log->error("MCP tool '$name' failed: " . $e->getMessage());

            return $this->jsonRpcResult($id, (object) [
                'content' => [
                    (object) [
                        'type' => 'text',
                        'text' => 'Internal error: ' . $e->getMessage(),
                    ],
                ],
                'isError' => true,
            ]);
        }
    }

    private function handleResourcesList(mixed $id): Response
    {
        return $this->jsonRpcResult($id, (object) ['resources' => []]);
    }

    private function handlePromptsList(mixed $id): Response
    {
        return $this->jsonRpcResult($id, (object) ['prompts' => []]);
    }

    private function executeTool(string $name, stdClass $args): mixed
    {
        $executor = $this->createExecutor();

        return match ($name) {
            'mcp_whoami' => $this->toolWhoami($executor),
            'list_entity_types' => $executor->getEntityTypes(),
            'describe_entity' => $executor->describe(
                $this->argEntityType($args)
            ),
            'get_record' => $executor->read(
                $this->argEntityType($args),
                $this->argString($args, 'id')
            ),
            'create_record' => $executor->create(
                $this->argEntityType($args),
                $this->argAttributes($args)
            ),
            'update_record' => $executor->update(
                $this->argEntityType($args),
                $this->argString($args, 'id'),
                $this->argAttributes($args)
            ),
            'delete_record' => $executor->delete(
                $this->argEntityType($args),
                $this->argString($args, 'id')
            ),
            'search_records' => $executor->search(
                $this->argEntityType($args),
                $this->optString($args, 'textFilter'),
                $this->optArray($args, 'where'),
                $this->optArray($args, 'select'),
                $this->optString($args, 'orderBy'),
                $this->optString($args, 'order'),
                $this->optInt($args, 'offset'),
                $this->optInt($args, 'maxSize')
            ),
            'get_related_records' => $executor->readRelated(
                $this->argEntityType($args),
                $this->argString($args, 'id'),
                $this->argString($args, 'link'),
                $this->optString($args, 'textFilter'),
                $this->optArray($args, 'where'),
                $this->optInt($args, 'offset'),
                $this->optInt($args, 'maxSize')
            ),
            'link_records' => $executor->link(
                $this->argEntityType($args),
                $this->argString($args, 'id'),
                $this->argString($args, 'link'),
                $this->argString($args, 'foreignId')
            ),
            'unlink_records' => $executor->unlink(
                $this->argEntityType($args),
                $this->argString($args, 'id'),
                $this->argString($args, 'link'),
                $this->argString($args, 'foreignId')
            ),
            'convert_lead' => $executor->convertLead($args),
            'get_stream' => $executor->getStreamNotes(
                $this->argEntityType($args),
                $this->argString($args, 'id'),
                $this->optInt($args, 'offset'),
                $this->optInt($args, 'maxSize')
            ),
            'post_to_stream' => $executor->addStreamNote(
                $this->argEntityType($args),
                $this->argString($args, 'id'),
                $this->argString($args, 'post'),
                (bool) ($args->isInternal ?? false)
            ),
            'mcp_setup_status' => $this->createSetupService()
                ->status($this->resolveUser()),
            'mcp_setup_preview' => $this->createSetupService()->preview(
                $this->resolveUser(),
                $this->argString($args, 'preset'),
                $this->argString($args, 'recordLevel'),
                $this->argOverrides($args)
            ),
            'mcp_setup_provision' => $this->createSetupService()->provision(
                $this->resolveUser(),
                $this->argString($args, 'preset'),
                $this->argString($args, 'recordLevel'),
                $this->argOverrides($args),
                ($args->confirm ?? false) === true,
                ($args->replaceExisting ?? false) === true
            ),
            default => throw new BadRequest("Unknown tool '$name'."),
        };
    }

    private function toolWhoami(RecordExecutor $executor): stdClass
    {
        $user = $this->resolveUser();

        return (object) [
            'id' => $user->getId(),
            'name' => $user->get('name'),
            'userName' => $user->get('userName'),
            'isAdmin' => $user->isAdmin(),
            'identitySource' => $this->identitySource(),
            'teams' => $user->get('teamsIds') ?? [],
        ];
    }

    private function createExecutor(): RecordExecutor
    {
        $user = $this->resolveUser();

        return $this->injectableFactory->createWith(RecordExecutor::class, [
            'user' => $user,
        ]);
    }

    private function createSetupService(): SetupService
    {
        return $this->injectableFactory->create(SetupService::class);
    }

    /**
     * Setup tools are listed only for admin sessions. This controls
     * visibility only — authorisation is enforced in SetupService.
     */
    private function setupToolsVisible(): bool
    {
        if (!$this->resolveUser()->isAdmin()) {
            return false;
        }

        return $this->createSetupService()->isEnabled();
    }

    /**
     * The effective user for this request. Either mapped from the
     * Cloudflare Access JWT, or the user the core API auth resolved.
     *
     * With a `noAuth` route the core middleware authenticates best-effort:
     * when no auth data is provided, the system user is installed and must
     * be rejected here unless a Cloudflare Access identity was verified.
     */
    private function resolveUser(): User
    {
        if ($this->cfAccessAuth->isEnabled() && $this->cfAccessIdentity !== null) {
            $user = $this->cfAccessAuth->resolveUserByEmail($this->cfAccessIdentity);

            if (!$user) {
                throw new Forbidden(
                    "MCP: No active EspoCRM user matches the Cloudflare Access identity."
                );
            }

            return $user;
        }

        $user = $this->user;

        if ($user->isSystem() || !$user->isActive()) {
            throw new Forbidden("MCP: Authentication required.");
        }

        return $user;
    }

    private ?string $cfAccessIdentity = null;

    public function setRequestContext(Request $request): void
    {
        if ($this->cfAccessAuth->isEnabled()) {
            $headers = [];

            $assertion = $request->getHeader('Cf-Access-Jwt-Assertion');

            if ($assertion) {
                $headers['Cf-Access-Jwt-Assertion'] = $assertion;
            }

            $cookies = [];

            $token = $request->getCookieParam('CF_Authorization');

            if (is_string($token) && $token !== '') {
                $cookies['CF_Authorization'] = $token;
            }

            $email = $this->cfAccessAuth->verifyAndGetEmail($headers, $cookies);

            if ($email !== null) {
                $this->cfAccessIdentity = $email;
            } elseif (
                $this->cfAccessAuth->espoAuthFallbackDisabled() &&
                !$request->hasHeader('Espo-Authorization')
            ) {
                throw new Forbidden("MCP: Cloudflare Access authentication required.");
            }
        }
    }

    private function identitySource(): string
    {
        if ($this->cfAccessIdentity !== null) {
            return 'cloudflare-access';
        }

        return 'espo-auth';
    }

    private function argEntityType(stdClass $args): string
    {
        $value = $args->entityType ?? null;

        if (!is_string($value) || $value === '') {
            throw new BadRequest("Missing required argument 'entityType'.");
        }

        return $value;
    }

    private function argString(stdClass $args, string $name): string
    {
        $value = $args->{$name} ?? null;

        if (!is_string($value) || $value === '') {
            throw new BadRequest("Missing required argument '$name'.");
        }

        return $value;
    }

    private function argAttributes(stdClass $args): stdClass
    {
        $value = $args->attributes ?? null;

        if (!($value instanceof stdClass)) {
            throw new BadRequest("Missing required argument 'attributes'.");
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function argOverrides(stdClass $args): array
    {
        $value = $args->overrides ?? null;

        if ($value === null) {
            return [];
        }

        if (!($value instanceof stdClass)) {
            throw new BadRequest("Argument 'overrides' must be an object.");
        }

        $result = [];

        foreach (get_object_vars($value) as $entityType => $shape) {
            if (!is_string($shape)) {
                throw new BadRequest("Override for '$entityType' must be a string.");
            }

            $result[$entityType] = $shape;
        }

        return $result;
    }

    private function optString(stdClass $args, string $name): ?string
    {
        $value = $args->{$name} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return ?array<int|string, mixed>
     */
    private function optArray(stdClass $args, string $name): ?array
    {
        $value = $args->{$name} ?? null;

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof stdClass) {
            return Json::decode(Json::encode($value), true);
        }

        throw new BadRequest("Argument '$name' must be an array.");
    }

    private function optInt(stdClass $args, string $name): ?int
    {
        $value = $args->{$name} ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        throw new BadRequest("Argument '$name' must be an integer.");
    }

    private function jsonRpcResult(mixed $id, stdClass $result): Response
    {
        $payload = (object) [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];

        return ResponseComposer::json($payload);
    }

    private function jsonRpcError(mixed $id, int $code, string $message): Response
    {
        $payload = (object) [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => (object) [
                'code' => $code,
                'message' => $message,
            ],
        ];

        return ResponseComposer::json($payload);
    }

    private function emptyResponse(): Response
    {
        return ResponseComposer::empty()->setStatus(202);
    }
}