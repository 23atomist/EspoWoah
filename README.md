# EspoMcp — MCP inside EspoCRM

A [Model Context Protocol](https://modelcontextprotocol.io) server implemented as an **EspoCRM module**. It exposes a single endpoint on your existing CRM URL:

```
POST https://crm.yourdomain.com/api/v1/mcp
```

No external process, no API keys, no god-mode credentials. Authentication uses the **same identity you use to sign into the CRM**, and every tool call runs with **your own ACL** (roles, teams, row-level security).

Designed for deployments fronted by **Cloudflare Access with OIDC** (e.g. Stalwart) — the module verifies the Cloudflare Access JWT and maps your OIDC email to your EspoCRM user, so "signing into the CRM" *is* the MCP authentication.

## Why this exists

External MCP servers for EspoCRM (e.g. EspoMCP) require an EspoCRM API key and must be able to reach the API from wherever they run. That breaks when:

- your CRM is behind Cloudflare Access / zero-trust — an API key doesn't pass CF Access, and
- you don't want a credential with bypass-everything permissions floating in a config file.

EspoMcp solves both by living inside the CRM:

| | External MCP + API key | EspoMcp (this) |
|---|---|---|
| Network reach | Needs direct API access | Same origin as the CRM |
| Auth | Static API key | Cloudflare Access OIDC (or standard Espo auth) |
| Permissions | API user, usually admin | **Your** user, per-tool ACL enforcement |
| Identity | Fixed | The user who signed in |
| Deployment | Extra service to run, monitor, patch | A module; deploy and forget |

## Tools

13 tools, generic over **all** entity types (including your custom entities) — a superset of what per-entity MCP servers offer, with dynamic schema discovery:

| Tool | Purpose |
|---|---|
| `mcp_whoami` | Who is the MCP acting as (user, teams, identity source) |
| `list_entity_types` | All entity types you can access, with per-type ACL levels |
| `describe_entity` | Full schema: fields, types, options, links, default sort |
| `get_record` | Read one record by ID |
| `create_record` | Create any entity type (duplicates detection included) |
| `update_record` | Partial update by ID |
| `delete_record` | Delete by ID |
| `search_records` | Full-text + structured `where` filters, ordering, pagination |
| `get_related_records` | Related records through any relationship link |
| `link_records` / `unlink_records` | Create/remove relationships |
| `convert_lead` | Convert Lead → Contact / Account / Opportunity |
| `get_stream` | Read a record's activity stream |
| `post_to_stream` | Post a note to a record's stream |

All record operations go through EspoCRM's own record services: validation, field filtering, duplicate detection, hooks, stream events and **ACL checks as the authenticated user** behave exactly as in the web UI.

## Requirements

- EspoCRM ≥ 8.4 (built against 10.0.7)
- PHP ≥ 8.3 with OpenSSL (part of EspoCRM's own requirements)
- Optional: Cloudflare Access on the CRM hostname

## Installation

### Install (module directory)

```bash
# from the EspoCRM root directory
cp -r path/to/EspoMcp custom/Espo/Modules/EspoMcp
chown -R www-data:www-data custom/Espo/Modules/EspoMcp
php command.php rebuild
```

(Or drop `custom/Espo/Modules/EspoMcp` from the release zip into your installation and run rebuild from the web UI if you don't have CLI access.)

### Enable Cloudflare Access identity (your setup)

Add to `data/config.php` (or via Administration → Entity Manager → ...):

```php
use Espo\Core\Utils\Config;

// config.php
return [
    'mcp' => [
        'cloudflareAccess' => [
            'enabled' => true,
            'teamName' => 'your-team',             // your-team.cloudflareaccess.com
            'applicationAud' => 'AUD-from-CF-Access-app',  // the Application's AUD tag
            'emailDomains' => ['yourdomain.com'],  // restrict which OIDC emails may map to users
            // 'allowedEmails' => ['you@yourdomain.com'],  // optional explicit allow-list
            // 'certsUrl' => 'https://your-team.cloudflareaccess.com/cdn-cgi/access/certs',
            // 'disableEspoAuthFallback' => true,  // optional: require CF Access for /mcp
        ],
        'security' => [
            'maxWhereDepth' => 5,
            'defaultMaxSize' => 50,
            'maxMaxSize' => 200,
        ],
    ],
];
```

### Service tokens (headless clients)

A Cloudflare Access **service token** asserts `common_name` and carries no
`email`, so it needs an explicit mapping to an EspoCRM user:

```php
'mcp' => ['cloudflareAccess' => [
    'serviceTokens' => [
        '<client-id>.access' => 'mcp@yourdomain.com',
    ],
]],
```

The mapped address must still satisfy `emailDomains` / `allowedEmails`, so a
mapping cannot widen access beyond the allow-list. Point it at a dedicated,
role-scoped user rather than a human admin — the assistant then has its own
identity in the audit trail and its own ceiling on what it can reach.

How it works:

1. A request hits `POST /api/v1/mcp` through your Cloudflare tunnel.
2. If Cloudflare Access authenticates the user (Stalwart OIDC sign-in), Cloudflare attaches a signed JWT — `Cf-Access-Jwt-Assertion` header or `CF_Authorization` cookie.
3. The module verifies the JWT signature against your team's public certs, checks `aud` (Application AUD), `iss` (team name), expiry, and the email domain allow-list.
4. The verified email maps to an active EspoCRM user — that user is the ACL context for every tool call.

If no CF Access JWT is present (e.g. local testing), standard EspoCRM API auth (Basic auth / auth token) works as fallback.

### Standard Espo auth (no Cloudflare)

Works out of the box. Point an MCP client at the URL with Basic auth:

```json
{
  "mcpServers": {
    "espocrm": {
      "type": "http",
      "url": "https://crm.yourdomain.com/api/v1/mcp",
      "headers": {
        "Authorization": "Basic <base64(username:password)>"
      }
    }
  }
}
```

## MCP client configuration (Cloudflare Access flow)

MCP clients that support HTTP transport with browser-based OAuth/SSO sign-in can use the CF Access session (cookie or token) directly. For CLI clients (e.g. Claude Code with `http` transport), the simplest is a **service token header** on a Cloudflare Access service-token application covering `/api/v1/mcp`:

```json
{
  "mcpServers": {
    "espocrm": {
      "type": "http",
      "url": "https://crm.yourdomain.com/api/v1/mcp",
      "headers": {
        "CF-Access-Client-Id": "<service-token-client-id>",
        "CF-Access-Client-Secret": "<service-token-secret>"
      }
    }
  }
}
```

> Cloudflare Access **service tokens** produce the same signed JWT assertion, so the module maps them to whichever user matches the service identity. For a personal-user mapping, sign in interactively (browser) and use an MCP client that forwards cookies, or use the Espo Basic-auth fallback for yourself.

Two common options for personal mapping:

- **Browser-based flow (recommended)**: use an MCP client with browser cookie support. Sign into the CRM through CF Access as usual; the `CF_Authorization` cookie authenticates MCP calls transparently — literally "the same way I sign into the CRM".
- **Dedicated MCP user**: create an EspoCRM user (e.g. `mcp@yourdomain.com`), have CF Access map the OIDC email to it, and scope its role to exactly what the assistant should see.

## Usage example

```
initialize  →  { "serverInfo": { "name": "espocrm-mcp", "user": { "name": "Thomas" ... } } }
tools/list  →  [13 tools]
tools/call search_records {
  "entityType": "Opportunity",
  "where": [ { "type": "and", "value": [
      { "type": "equals", "attribute": "stage", "value": "Prospecting" },
      { "type": "after", "attribute": "createdAt", "value": "2026-08-01" }
  ] } ],
  "orderBy": "amount", "order": "desc", "maxSize": 10
}
```

The `where` grammar is EspoCRM's own — `equals`, `in`, `contains`, `between`, `after`, `linkedWith`, `arrayAnyOf`, and combinations via `and`/`or`/`not`. Nested groups are limited by `mcp.security.maxWhereDepth`.

## Security notes

- Every tool call executes with the resolved user's ACL — no admin bypass. If your role says read-only on Opportunities, MCP can't write Opportunities either.
- CF Access JWTs are fully verified: signature against CF's published certs (cached 1h), `aud`, `iss`, `exp`, `iat` skew, and email/domain allow-lists.
- Where-filter grammar is allow-listed; nesting depth and page sizes are capped.
- Unauthenticated requests are rejected — never executed as the system user.
- `GET /api/v1/mcp` discloses only non-sensitive server info (name, version, protocol, your user identity).

## Uninstall

```bash
rm -rf custom/Espo/Modules/EspoMcp
php command.php rebuild
```

## License

MIT