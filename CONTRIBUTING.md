# Contributing

## Getting set up

```bash
composer install
composer test
composer lint
```

PHP 8.3 or newer is required. Use a version manager rather than a system PHP.

## The one structural rule

Classes under `Tools/Mcp/Security/` and `Tools/Mcp/Setup/` that carry decision
logic **must not import anything from `Espo\`**. They take plain arrays.

This is what makes the access-control logic — deny-lists, presets, ACL clamping —
unit-testable without an EspoCRM installation. Espo-dependent orchestration
belongs in `SetupService`, which those pure classes feed. A pull request that adds
an `Espo\` import to a pure class will be asked to restructure.

## Tests

- New decision logic needs unit tests, written first.
- Changes to access control need a test asserting that the change cannot **widen**
  access unexpectedly. Clamping never widens; deny-lists are additive only.
- Integration changes need a note in the integration checklist in
  `docs/superpowers/plans/`, since they cannot run in CI without a live EspoCRM.

## Commits

Conventional commits: `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`.
Security-relevant fixes should say plainly what was reachable and how, as the
changelog entries do.

## Reporting security issues

Do not open a public issue. See `SECURITY.md`.
