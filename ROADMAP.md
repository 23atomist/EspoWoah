# Roadmap

## OAuth 2.1 browser sign-in

**Status:** investigated, deferred. Recorded here so the deferral is on the record.

The MCP authorization spec makes the MCP server an OAuth 2.1 *resource server*: it
returns `401` with `WWW-Authenticate: Bearer resource_metadata="..."`, publishes RFC
9728 protected-resource metadata, and the client performs PKCE against an
authorization server, opening a browser. No credential is stored in any client
config file, and every call carries the signed-in user's own identity.

That is the correct end state for this module, and it would make the original
promise — "the same identity you use to sign into the CRM" — true on installations
with no Cloudflare Access.

**Why it is not in this release:**

1. EspoCRM cannot act as an identity provider. It is an OIDC *client* only. So the
   module would have to be its own authorization server, delegating the login step
   to the existing Espo web session — which is what would make it work regardless
   of how an installation authenticates: password, OIDC, LDAP, 2FA.
2. Scope is roughly 800-1200 lines: protected-resource and authorization-server
   metadata, `/authorize` plus a consent page, `/token` with PKCE and refresh,
   client registration, a token entity with revocation, and audience binding per
   RFC 8707.
3. **Open question, needs a spike before committing:** RFC 9728 expects the metadata
   at `https://<host>/.well-known/oauth-protected-resource/api/v1/mcp`. EspoCRM
   routes live under `/api/v1/`, and it is unresolved whether a module's
   `routes.json` can claim a `/.well-known/` path. If it cannot, installation needs
   a web-server rewrite rule, which weakens the drop-in extension story.

Until then the provisioned API key (`mcp_setup_provision`) is the default, and
Cloudflare Access remains the strongest option for those who have it.

## Server-verified consent for `mcp_setup_provision`

**Status:** proposed. The gap is documented in `SECURITY.md` under "Known
limitations"; this is the fix.

`mcp_setup_provision` today requires `confirm: true`, and `confirm` is a flag the
*assistant* sets. Nothing binds a provisioning to a preview having been rendered,
let alone seen by a human. The consent model is therefore asserted, not enforced —
in a threat model whose premise is an assistant that may have been told to skip
the preview.

Proposed fix: `mcp_setup_preview` returns a hash of the resolved matrix it just
rendered (preset, record level, overrides, and the built role data), and
`mcp_setup_provision` takes that hash as a required argument. The server recomputes
the hash from the arguments it was given and refuses if it does not match. The
server can then verify that the matrix being provisioned is the matrix that was
previewed, and that a preview happened at all.

This does not prove a *human* saw the preview — no server-side check can — but it
removes the case that matters most: an assistant provisioning a different, wider
matrix than the one it displayed. It is a protocol change (a new required
argument), so it belongs to a release where the tool schema may change.
