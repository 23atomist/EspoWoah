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

### Removed

- The committed `EspoMcp-v1.0.0.zip` build artifact. Releases ship as GitHub
  Release assets.

## [1.0.0] - 2026-09-07

Initial release: MCP server as an EspoCRM module, 13 record tools, Cloudflare
Access JWT identity with service-token support.
