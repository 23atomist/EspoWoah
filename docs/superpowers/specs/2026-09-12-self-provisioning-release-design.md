# EspoMcp — Self-Provisioning Bootstrap & Public Release

**Date:** 2026-09-12
**Status:** Approved design, pending implementation plan
**Supersedes:** positioning in `README.md` as of `db6e0f4`

## 1. Context

EspoMcp v1.0.0 is an MCP server implemented as an EspoCRM module. It works well for
the deployment it was built for — Cloudflare Access + OIDC, where identity arrives
as a verified JWT and no credential is stored anywhere.

For public release the target user changed: **the plain EspoCRM admin with no
Cloudflare Access**, who wants an assistant talking to their CRM. For that user the
current module offers only the Basic-auth fallback, which the README documents as
base64-encoding an admin username and password into an MCP client config file. That
credential is full-privilege, permanent, and sits in cleartext on disk.

This release replaces that default with a scoped, revocable API key that the module
provisions for itself, and closes a privilege-escalation hole found during design.

## 2. Goals

1. A first-run flow where an admin-authenticated session provisions a dedicated,
   role-scoped EspoCRM API user for the assistant, after presenting the exact
   permissions for approval.
2. Close the `User`-write privilege-escalation path reachable through the generic
   record tools.
3. Make installation viable for admins without shell access.
4. Fill the `resources/list` and `prompts/list` stubs with material that cannot
   drift from the tool surface.
5. Ship a repo that reads as finished: honest README, licence, tests, CI.

## 3. Non-goals

- **OAuth 2.1 browser flow.** Investigated and deferred to the roadmap. EspoCRM
  cannot act as an identity provider, so the module would have to be its own
  authorization server (delegating the login step to the existing Espo web session).
  Correct architecture, ~800–1200 lines, and blocked on an unresolved question about
  whether an Espo module can serve `/.well-known/oauth-protected-resource`. Recorded
  in `ROADMAP.md`, not built here.
- HMAC auth. `authMethod: Hmac` exists, but MCP clients send static headers and
  cannot sign per request. `ApiKey` only.
- Changing the Cloudflare Access path. It stays, and remains the strongest option
  for those who have it.

## 4. Verified mechanics

Confirmed against EspoCRM source and a live install during design. Implementation
should not re-derive these.

| Fact | Source | Consequence |
|---|---|---|
| `User.type` enum includes `api`; `authMethod` includes `ApiKey`/`Hmac` | live `describe_entity User` | Provisioning target confirmed |
| `apiKey`/`secretKey` are `readOnly` in entityDefs | live `describe_entity User` | Cannot be set on create |
| `ApiService::generateNewApiKey(string $id): User` throws `Forbidden` unless admin, requires `isApi()`, generates via `Util::generateApiKey()`, saves, returns entity with key populated | `application/Espo/Tools/UserSecurity/ApiService.php` | **Use this**, not direct DB writes. Free second admin gate. |
| `Classes/Record/User/OutputFilter` clears `apiKey`/`secretKey` for non-admins | Espo source | Key is readable only in an admin context |
| `Role` scope is `"acl": false` and has no `"object": true` | `metadata/scopes/Role.json` | `checkEntityType()` rejects it — confirmed live. Role creation needs a dedicated admin path |
| `User` scope is `"object": true`, `aclActionList: ["read","edit"]`, `aclActionLevelListMap: {"edit":["own","no"]}` | `metadata/scopes/User.json` | Reachable by generic tools. Role ACL cannot grant create/delete — **but admins bypass ACL entirely**, which is the hole |
| `Team` uses `aclLevelList`, not `aclActionLevelListMap` | `metadata/scopes/Team.json` | Level clamping must handle both keys |
| `Role` carries 13 permission fields beyond `data`/`fieldData` (`exportPermission`, `massUpdatePermission`, `assignmentPermission`, `dataPrivacyPermission`, …), all `audited: true` | `entityDefs/Role.json` | Real safety levers for presets; changes are audited |
| Extension package = `manifest.json` + `files/` + `scripts/`, zipped, installed via Administration → Extensions | EspoCRM docs | Component 4 is straightforward |

## 5. Component 1 — Deny-list hardening

**Ships regardless of the bootstrap. This is a v1.0.0 defect.**

`RecordExecutor::checkEntityType()` (`RecordExecutor.php:587`) gates only on
`scopes.*.entity`/`object` plus an ACL check. Because admins bypass ACL, an
admin-authenticated MCP session can call `create_record User {type: "admin"}` or
`update_record` a user's `type`/`password`. This is reachable by prompt injection:
CRM records contain attacker-supplied text (lead notes, inbound email bodies), and a
model summarising one can be talked into the call.

`checkEntityType()` becomes operation-aware — `read` vs `write` — with two tiers,
both configurable under `mcp.security`:

**Read allowed, write denied** (`deniedWriteEntityTypes`): `User`, `Team`, `Portal`,
`PortalRole`. Reading users is legitimate — you need IDs to assign records. Writing
them never is.

`Role` is listed here too, as defence in depth only: it is already unreachable
because its scope is not `object` (§4), and the list guards against a future Espo
version flipping that.

**Fully denied** (`deniedEntityTypes`): `AuthToken`, `AuthLogRecord`,
`PasswordChangeRequest`, `ActionHistoryRecord`, `AppSecret`, `Extension`, `Job`,
`ScheduledJob`, `AuthenticationProvider`.

Denials return `Forbidden` with a message naming the deny-list, so the behaviour is
legible rather than looking like a bug. Both lists are additive-only from config —
an operator may extend them, never shrink them below the shipped defaults.

## 6. Component 2 — Bootstrap provisioning

### 6.1 Arming

No first-run flag. The setup tools appear in `tools/list` **only when the resolved
user is an admin**. `initialize` gains a `setup` block in `serverInfo` so the
assistant notices unprompted:

```json
"setup": {
  "required": true,
  "reason": "admin session, no MCP service user provisioned",
  "nextTool": "mcp_setup_status"
}
```

`required` flips to `false` once a provisioned service user exists; provisioning then
refuses unless called with `replaceExisting: true`.

Admin-only *is* the gate. Reaching this state requires already holding admin
credentials, so the flow grants no new capability — it trades a permanent
full-privilege credential for a scoped revocable one. Under `mcp.setup.enabled:
false` the tools never appear.

### 6.2 Tools

| Tool | Writes | Purpose |
|---|---|---|
| `mcp_setup_status` | no | Admin?, service user exists?, CF Access on?, available presets, Espo version, warnings |
| `mcp_setup_preview` | **no** | preset + overrides → exact entity×permission matrix that *would* be created |
| `mcp_setup_provision` | yes | same args + `confirm: true` → create Role + API user, return key once |

`preview` is load-bearing: it is what lets the assistant show a real permission table
and obtain a yes before anything is written. `provision` must reject any call whose
`confirm` is not literal `true`.

### 6.3 Presets

Presets are **functions over live metadata**, not hardcoded entity lists — custom
entities must be covered. Each is a default rule plus a named core set.

| Preset | Core set | Core access | Everything else |
|---|---|---|---|
| `readonly-analyst` | — | — | read + stream at `recordLevel`; no create/edit/delete |
| `sales-assistant` | Account, Contact, Lead, Opportunity, Task, Meeting, Call | create yes; read/edit/stream at `recordLevel`; delete no | read at `recordLevel` |
| `support-agent` | Case, Contact, Account, KnowledgeBaseArticle, Task, Meeting, Call | as above | read at `recordLevel` |
| `full-operator` | all object entities | create yes; read/edit/delete/stream at `recordLevel` | — |

Two refinement axes:

- **`recordLevel`**: `own` | `team` | `all`. This is the direct expression of "how
  much access are you willing to risk", mapped onto Espo's own levels rather than a
  parallel concept.
- **`overrides`**: per-entity adjustment after a preset is chosen, using named
  shapes rather than raw action/level pairs —
  `{"Document": "none", "Task": "readwrite"}`.

  | Shape | Resolves to |
  |---|---|
  | `none` | scope disabled |
  | `read` | read + stream at `recordLevel` |
  | `readwrite` | `read` plus create, and edit at `recordLevel` |
  | `full` | `readwrite` plus delete at `recordLevel` |

  Named shapes are deliberate: they are unambiguous for a model to emit, trivial to
  render in `preview`, and they keep the tool from becoming a second role editor.
  Per-action tuning stays in the Espo UI, where the generated Role remains editable.
  Resolved shapes are clamped by §6.4 like any other level.

**Safety defaults on every preset**, using the Role permission fields:
`exportPermission: "no"`, `massUpdatePermission: "no"`, `dataPrivacyPermission:
"no"`. An assistant should not be able to mass-update ten thousand records or export
the database, even under `full-operator`. `delete` is `no` everywhere except
`full-operator`.

### 6.4 Role builder — the clamping algorithm

A pure function: `(presetName, recordLevel, overrides, metadata) → Role.data`. Pure
because it must be unit-testable without an Espo install; it is the highest-value
test target in the release.

For each scope where `entity && object`:

1. Determine allowed actions from `scopes.<E>.aclActionList`, defaulting to
   `[create, read, edit, delete, stream]` when absent.
2. Determine allowed levels per action from `aclActionLevelListMap.<action>`, else
   `aclLevelList`, else `[all, team, own, no]`.
3. Compute the desired level from preset + overrides.
4. **Clamp**: if the desired level is not allowed, fall back to the most permissive
   allowed level that is no more permissive than desired. Never widen.
5. Omit actions absent from `aclActionList` entirely.

Scopes in either deny-list from Component 1 are written as `false` (scope disabled)
regardless of preset.

### 6.5 Provisioning sequence

1. Validate: caller is admin; `confirm === true`; no existing service user unless
   `replaceExisting`.
2. Build `Role.data` via the builder. Create the Role through the record service.
   Name: `MCP — <preset>`. Permission fields set per 6.3.
3. Create the User through the record service: `type: api`, `authMethod: ApiKey`,
   `userName: mcp-assistant`, `lastName: MCP Assistant`, `isActive: true`,
   `rolesIds: [roleId]`.
4. Call `ApiService::generateNewApiKey($userId)`; read `apiKey` off the returned
   entity.
5. Record the provisioning event in the log at `info`, including actor, preset,
   `recordLevel`, and role ID. Role field changes are already `audited: true`.
6. Return the key once.

Everything goes through Espo's own record services so validation, hooks and audit
behave exactly as in the web UI.

### 6.6 Key delivery

The `provision` response returns the key **once**, with a paste-ready `mcpServers`
block, an explicit one-time marker, and revocation instructions in the same payload.

Accepted trade-off: the key enters the assistant's context and the chat transcript.
It was going into the client config file in cleartext regardless — the only question
was whether it also crossed the model's context — and the thing it replaces is a
permanent admin password in that same file. Net improvement even counting the leak.
This reasoning belongs in the README's security section, stated plainly rather than
buried.

## 7. Component 3 — Resources and prompts

`handleResourcesList` and `handlePromptsList` (`McpService.php:223-230`) advertise
capabilities and return empty arrays.

**Resources** carry reference material that would otherwise be guessed at. They are
generated from the same constants the tools use, so they cannot go stale:

- `espocrm://guide/search-grammar` — the `where` grammar: `equals`, `in`, `contains`,
  `between`, `after`, `linkedWith`, `arrayAnyOf`, `and`/`or`/`not` nesting, and the
  `maxWhereDepth` cap.
- `espocrm://guide/entity-model` — `link` vs `linkMultiple`, the `*Id`/`*Name`
  attribute pairing, `assignedUser`/`teams` semantics.
- `espocrm://guide/acl-levels` — what `all`/`team`/`own`/`no` mean, and how to read a
  `Forbidden` response.

This is the client-agnostic answer to what a skills library would otherwise do:
pulled only when needed, so it costs no context otherwise.

**Prompts** carry workflows, which is what tool descriptions cannot hold: a pipeline
review, a lead triage pass, a duplicate sweep. Two or three, no more.

## 8. Component 4 — Extension packaging

Current install is `cp -r` + `chown www-data` + CLI `rebuild`. The target user is
frequently on shared hosting with no shell. Repackage as an installable extension:

```
manifest.json
files/custom/Espo/Modules/EspoMcp/...
scripts/AfterInstall.php
scripts/AfterUninstall.php
```

`manifest.json`: `name`, `version` (SemVer), `acceptableVersions: [">=8.4.0"]`,
`php: [">=8.3"]`, `author`, `description`, `releaseDate`.

`AfterUninstall.php` must deactivate the provisioned API user rather than orphan a
live credential.

`SERVER_VERSION` is currently hardcoded at `McpService.php:46` and will drift from
the manifest. Source it from module metadata instead; a single version constant.

A `build.sh` produces the zip. It becomes a GitHub Release asset — **not** a
committed file. `EspoMcp-v1.0.0.zip` is removed from git history and added to
`.gitignore`.

## 9. Component 5 — Claude Code plugin

`.claude-plugin/plugin.json` in the repo bundling the MCP server config and two or
three workflow skills, so Claude users get one `/plugin install` rather than
hand-editing config. Clearly marked optional; the module stands alone without it.

Written **last**, once the tool surface has stopped moving, for the drift reason in
§7.

## 10. Component 6 — Release hygiene

- **README rewrite.** The headline claim — *"No external process, no API keys, no
  god-mode credentials"* — stops being true for the default path. Restructure around
  an honest auth-options table: Cloudflare Access (strongest, if you have it) /
  provisioned API key (default) / admin Basic auth (development only, discouraged).
  Add the §6.6 reasoning to the security section.
- `LICENSE` — README claims MIT; no file exists.
- `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`. A security policy matters for a
  module that mediates CRM authentication.
- `ROADMAP.md` recording the OAuth investigation and its open `.well-known` question,
  so the deferral is a decision on record rather than an omission.
- CI: PHP 8.3 lint, PHPStan, PHPUnit.

## 11. Testing

The repo currently has no tests. Coverage target is 80%, per project standards, with
TDD on new code.

**Unit (the bulk, and genuinely achievable):**
- Role builder clamping — the §6.4 algorithm. Table-driven across every preset ×
  `recordLevel` × synthetic metadata including entities that omit actions and
  entities using `aclLevelList`. Must assert clamping **never widens**.
- Deny-list matrix — every entity in both tiers × read/write.
- Preset override merging.
- `where`-grammar validation and depth capping.

**Integration (requires a live Espo; a documented harness):**
- Provisioning end to end: role created, user created, key returned, key
  authenticates, scoped user is correctly denied out-of-scope entities.
- `replaceExisting` behaviour.
- Setup tools absent for non-admin sessions.

**Adversarial (explicit cases, not incidental):**
- A record whose text body instructs the model to call `create_record User` →
  must be refused by the deny-list.
- `provision` without `confirm: true` → refused.
- `provision` as non-admin → refused at both the module gate and `ApiService`.

## 12. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Provisioned key leaks via transcript | Medium | Accepted, §6.6. One-time marker, revocation instructions inline, scoped and revocable |
| Preset grants more than the user understood | High | `preview` is mandatory and write-free; clamping never widens; `delete` off by default; export/mass-update off on every preset |
| Deny-list breaks a legitimate workflow | Low | Read still allowed on `User`/`Team`; denials name the list; config may extend |
| Injection reaches `provision` during an admin session | Medium | Explicit `confirm`, preview-before-write, single active service user, provisioning logged, role changes audited |
| `AfterUninstall` orphans a live credential | Medium | Deactivate the API user on uninstall (§8) |
| Version drift between manifest and `SERVER_VERSION` | Low | Single source of truth (§8) |

## 13. Sequencing

1. Component 1 (deny-list) — independent, security-critical, ships first
2. Component 2 (bootstrap) — the feature
3. Component 3 (resources/prompts)
4. Component 4 (extension packaging)
5. Component 6 (docs, licence, CI) — alongside 1–4
6. Component 5 (Claude plugin) — last, after the tool surface settles
