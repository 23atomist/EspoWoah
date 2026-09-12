# Security Policy

## Supported versions

Only the latest released version receives security fixes.

## Reporting a vulnerability

Please report security issues **privately**, not as a public GitHub issue.

Use GitHub's private vulnerability reporting ("Report a vulnerability" on the
Security tab). Include the EspoCRM version, the module version, and the steps to
reproduce.

Expect an acknowledgement within 7 days. Please allow 90 days before public
disclosure.

## Scope

In scope:

- Authentication bypass on `/api/v1/mcp`, including Cloudflare Access JWT
  verification and the Espo auth fallback.
- Privilege escalation through any MCP tool, including bypassing the entity
  deny-lists or the ACL applied to the resolved user.
- Flaws in the first-run setup flow that would grant more access than the
  approved preview, or that let a non-admin provision a service user.
- Leakage of API keys, secret keys, or Cloudflare certificates.

Out of scope:

- Vulnerabilities in EspoCRM itself — report those to the EspoCRM project.
- An administrator deliberately granting the assistant wide access through a
  preset. `mcp_setup_preview` exists to make that an informed choice, but the
  server does not enforce that it was shown — see "The consent model is
  asserted by the assistant, not enforced by the server" below. The limitation
  is stated rather than assumed away: a provisioning that the administrator did
  not see previewed is a real gap in that flow, tracked for a later release,
  not a vulnerability report we can act on per-install.
- Prompt injection that causes the assistant to perform actions **within** the
  permissions it was granted. The deny-lists and presets bound the blast radius;
  they cannot stop a model from misusing access it legitimately holds.

## Design notes relevant to security

- Every record operation runs with the resolved user's ACL, and the module adds
  no bypass of its own. For a non-admin identity that ACL is the boundary. For
  an administrator it is not — EspoCRM administrators bypass ACL by design —
  which is precisely why the deny-lists below exist and why a role-scoped,
  non-admin service user is the recommended identity.
- `User`, `Team`, `Role`, `Portal` and `PortalRole` are readable but never
  writable through MCP. Auth-surface entities are refused outright. These
  deny-lists apply even to administrators, because an administrator bypasses
  ordinary EspoCRM ACL.
- The API key returned by `mcp_setup_provision` is shown once and is scoped by
  the role the administrator approved. Deactivating the service user revokes it,
  but that revocation is **reversible**: a later `mcp_setup_provision` with
  `replaceExisting: true` reactivates the user and mints a new key. It does not
  do so silently — the response carries `reactivated: true` and a warning saying
  so — but an administrator who wants the revocation to be permanent should
  delete the service user, not merely deactivate it.
- Re-provisioning renames (never deletes) any `MCP — …` role the service user
  currently holds, appending ` (superseded)`, and the returned `warning` names
  the live role by **id** as well as by name. Without this, successive runs leave
  several roles with the same name and "edit the role" is ambiguous.

### Known limitations

- **Relationship writes check only the entity you name.** `link_records` and
  `unlink_records` verify the deny-list against the near entity type, not the
  far side of the relationship. With the shipped lists this is not reachable —
  every privilege-granting join (User↔Team, User/Team↔Role) requires a
  write-denied type as the near side — but a custom type added to
  `deniedWriteEntityTypes` can still have its relationships mutated from the
  far side.
- **Stream tools check the parent record, not `Note`.** `post_to_stream` and
  `get_stream` check the record you name; they do not check the `Note` entity
  type itself. An operator who adds `Note` to `deniedEntityTypes` will find
  those two tools do not honour it.
- **`list_entity_types` is not filtered by the deny-lists.** Fully denied
  types are still advertised, then refused on use. This is noise rather than
  exposure — no denied type becomes reachable — but the listing does not
  reflect the policy.
- **The consent model is asserted by the assistant, not enforced by the
  server.** `mcp_setup_provision` requires `confirm: true`, and `confirm` is a
  flag the *assistant* sets. Nothing in the protocol binds a provisioning to a
  preview having been rendered, or to a human having seen it. So "the
  administrator sees the exact permissions before they are created" holds only
  while the assistant cooperates — and the threat model this flow is written
  against is one in which the assistant may have been instructed not to. What
  is genuinely enforced is narrower and still worth having: the caller must
  hold an administrator session, setup must be enabled, the preset and record
  level are validated, and the resulting role is clamped by the same deny-lists
  as everything else. The fix — `provision()` echoing back a hash of the matrix
  `preview()` returned, so the server can verify the approved matrix is the one
  being written — is a protocol change and is on the roadmap, not in this
  release.
- **`Email` is writable, and writing it can send mail.** `Email` is an `object`
  scope in EspoCRM core and is on neither deny-list, so the record tools can
  create and update it. Core sends via SMTP when an email reaches status
  `Sending`: `Espo\Classes\RecordHooks\Email\AfterUpdate` on the update path,
  and `Espo\Services\Email::create()` on the create path. In an
  admin-authenticated session core's `CheckFromAddress` hook returns early for
  administrators, so the `from` address is not constrained either. That is a
  live outbound email channel from a trusted domain, reachable by prompt
  injection. It is **not** privilege escalation — no permission is gained — but
  it is a real exfiltration and impersonation channel, and a fair answer to
  "the deny-lists bound the blast radius". An operator who does not want it can
  add `Email` to `mcp.security.deniedWriteEntityTypes`, which leaves email
  readable through MCP but refuses every create, update, delete and relationship
  write on it.

These are recorded limitations, not unknown gaps: the deny-lists are enforced
at the point of use in every case.
