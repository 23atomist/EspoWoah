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
  preset. The preview step exists so that this is an informed choice.
- Prompt injection that causes the assistant to perform actions **within** the
  permissions it was granted. The deny-lists and presets bound the blast radius;
  they cannot stop a model from misusing access it legitimately holds.

## Design notes relevant to security

- Every record operation runs with the resolved user's ACL. There is no admin
  bypass path through the record tools.
- `User`, `Team`, `Role`, `Portal` and `PortalRole` are readable but never
  writable through MCP. Auth-surface entities are refused outright. These
  deny-lists apply even to administrators, because an administrator bypasses
  ordinary EspoCRM ACL.
- The API key returned by `mcp_setup_provision` is shown once and is revocable by
  deactivating the service user. It is scoped by the role the administrator
  approved.

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

These are recorded limitations, not unknown gaps: the deny-lists are enforced
at the point of use in every case.
