# Changelog

All notable changes to this project are documented here.
Format follows Keep a Changelog; versioning follows SemVer.

## [Unreleased]

### Security

- **Entity-type deny-lists for the generic record tools.** Administrators bypass
  EspoCRM's ACL, so an admin-authenticated MCP session could previously call
  `create_record User {type: "admin"}` or reset a password through
  `update_record`. This was reachable by prompt injection, since CRM records
  carry attacker-supplied text such as lead notes and inbound email bodies.
  `User`, `Team`, `Role`, `Portal` and `PortalRole` are now readable but not
  writable through MCP; auth-surface entities (`AuthToken`, `AuthLogRecord`,
  `PasswordChangeRequest`, `Extension`, `Job`, `ScheduledJob`,
  `AuthenticationProvider`, `AppSecret`, `ActionHistoryRecord`) are refused
  outright. Configurable via `mcp.security.deniedEntityTypes` and
  `mcp.security.deniedWriteEntityTypes`, which are additive only and cannot
  re-enable a shipped denial.

### Added

- **First-run setup.** `mcp_setup_status`, `mcp_setup_preview` and
  `mcp_setup_provision` provision a role-scoped EspoCRM API user for the
  assistant, replacing the practice of putting admin credentials in an MCP
  client config file. Admin-only; `preview` writes nothing and returns the exact
  entity-by-permission matrix for approval before anything is created.
- Four access presets — `readonly-analyst`, `sales-assistant`, `support-agent`,
  `full-operator` — with an `own`/`team`/`all` record level and per-entity
  overrides. Export, mass-update and data-privacy permissions are disabled on
  every preset; delete is disabled on all but `full-operator`.
- `resources/list` and `resources/read`: guides for the where-grammar, entity
  model conventions, and ACL levels.
- `prompts/list` and `prompts/get`: pipeline review, lead triage, duplicate sweep.
- PHPUnit test suite and CI on PHP 8.3, 8.4 and 8.5.
- `LICENSE` (MIT — previously claimed in the README with no file present).

### Fixed

- `mcp.setup.enabled: false` now disables the setup flow rather than only hiding
  the three tools from `tools/list`. `mcp_setup_status`, `mcp_setup_preview` and
  `mcp_setup_provision` each refuse with `Forbidden` when it is off, so calling a
  tool by name no longer works around the setting.
- Re-provisioning an existing service user reports a reactivation instead of
  performing one silently: the response carries `reactivated: true` and a warning
  saying that a deliberately revoked service user has been reactivated with a new
  key.
- The provisioning warning now names the live role by **id**, and re-provisioning
  renames (never deletes) any `MCP — …` role the service user held, appending
  ` (superseded)` — so "edit the role" can no longer point at an orphaned
  duplicate.
- `recordLevel: "team"` now returns an explicit `warnings` entry from both
  `mcp_setup_preview` and `mcp_setup_provision`: the service user is created with
  no teams, so team-level access resolves to no records until an administrator
  assigns teams to it.
- Unexpected internal errors return a generic message to the MCP client instead
  of the exception text, which could carry SQL fragments or schema names. The
  full message is still logged at error level.
- README: corrected a false claim that there is "no admin bypass" (administrators
  bypass ACL by design — the deny-lists, which apply to administrators too, are
  what stop MCP writing the auth surface), corrected "no API keys", and added the
  setup tools, the deny-list config keys, and the resources and prompts.
- `SECURITY.md`: recorded that `confirm` is an assistant-asserted flag rather
  than server-enforced proof that a preview was shown, and added the `Email`
  outbound-mail channel to the known limitations.

### Removed

- The committed `EspoMcp-v1.0.0.zip` build artifact. Releases ship as GitHub
  Release assets.

## [1.0.0] - 2026-09-07

Initial release: MCP server as an EspoCRM module, 13 record tools, Cloudflare
Access JWT identity with service-token support.
