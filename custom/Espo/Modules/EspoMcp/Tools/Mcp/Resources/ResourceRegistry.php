<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Licensed under the MIT License.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Tools\Mcp\Resources;

/**
 * Reference guides exposed as MCP resources.
 *
 * These carry material a model would otherwise guess at — the
 * where-grammar above all. Resources are pulled only when needed, so they
 * cost no context otherwise, and they live beside the code rather than in
 * a parallel docs tree that can go stale.
 */
final class ResourceRegistry
{
    private const string URI_SEARCH_GRAMMAR = 'espocrm://guide/search-grammar';
    private const string URI_ENTITY_MODEL = 'espocrm://guide/entity-model';
    private const string URI_ACL_LEVELS = 'espocrm://guide/acl-levels';

    /**
     * @return array<int, array<string, string>>
     */
    public function list(): array
    {
        return [
            [
                'uri' => self::URI_SEARCH_GRAMMAR,
                'name' => 'EspoCRM search grammar',
                'description' => 'The where-filter grammar used by search_records and ' .
                    'get_related_records: operators, nesting, and worked examples.',
                'mimeType' => 'text/markdown',
            ],
            [
                'uri' => self::URI_ENTITY_MODEL,
                'name' => 'EspoCRM entity model conventions',
                'description' => 'Link vs linkMultiple, the Id/Name attribute pairing, and ' .
                    'assignedUser/teams semantics when creating or updating records.',
                'mimeType' => 'text/markdown',
            ],
            [
                'uri' => self::URI_ACL_LEVELS,
                'name' => 'EspoCRM ACL levels',
                'description' => 'What all/team/own/no mean, and how to interpret a Forbidden ' .
                    'response from a record tool.',
                'mimeType' => 'text/markdown',
            ],
        ];
    }

    public function read(string $uri): ?string
    {
        return match ($uri) {
            self::URI_SEARCH_GRAMMAR => $this->searchGrammar(),
            self::URI_ENTITY_MODEL => $this->entityModel(),
            self::URI_ACL_LEVELS => $this->aclLevels(),
            default => null,
        };
    }

    private function searchGrammar(): string
    {
        return <<<'MD'
        # EspoCRM where-filter grammar

        `search_records` and `get_related_records` accept a `where` array. Each item is
        an object with `type`, usually `attribute`, and usually `value`.

        ## Comparison

        | type | value | Meaning |
        |---|---|---|
        | `equals` | scalar | exact match |
        | `notEquals` | scalar | exact non-match |
        | `greaterThan` / `lessThan` | scalar | strict comparison |
        | `greaterThanOrEquals` / `lessThanOrEquals` | scalar | inclusive comparison |
        | `in` / `notIn` | array | membership |
        | `isNull` / `isNotNull` | — | null check, omit `value` |
        | `isTrue` / `isFalse` | — | boolean fields, omit `value` |

        ## Text

        | type | value | Meaning |
        |---|---|---|
        | `contains` / `notContains` | string | substring |
        | `startsWith` / `endsWith` | string | anchored substring |
        | `like` / `notLike` | string | SQL LIKE, `%` allowed |

        ## Dates

        | type | value | Meaning |
        |---|---|---|
        | `after` / `before` | date or datetime | strict |
        | `between` | `[from, to]` | inclusive range |
        | `today`, `past`, `future` | — | omit `value` |
        | `lastXDays`, `nextXDays` | integer | relative window |

        ## Links

        | type | value | Meaning |
        |---|---|---|
        | `linkedWith` | array of IDs | related to any of these |
        | `notLinkedWith` | array of IDs | related to none of these |
        | `isLinked` / `isNotLinked` | — | any relation at all, omit `value` |

        ## Array and multi-enum fields

        | type | value |
        |---|---|
        | `arrayAnyOf` | array of options |
        | `arrayNoneOf` | array of options |
        | `arrayAllOf` | array of options |
        | `arrayIsEmpty` / `arrayIsNotEmpty` | — |

        ## Grouping

        `and`, `or` and `not` take a `value` array of nested items and no `attribute`.
        Nesting depth is capped by `mcp.security.maxWhereDepth` (default 5); exceeding
        it is an error, not a silent truncation.

        ## Worked example

        Opportunities in Prospecting, created since August, for either of two accounts:

        ```json
        [
          {
            "type": "and",
            "value": [
              { "type": "equals", "attribute": "stage", "value": "Prospecting" },
              { "type": "after", "attribute": "createdAt", "value": "2026-08-01" },
              { "type": "linkedWith", "attribute": "account", "value": ["id1", "id2"] }
            ]
          }
        ]
        ```

        ## Notes

        - `attribute` is the field name from `describe_entity`, not the display label.
        - For a link field, filter on the link name with `linkedWith`, or on `<link>Id`
          with `equals`. Do not filter on `<link>Name`.
        - `maxSize` is capped by `mcp.security.maxMaxSize` (default 200). Page with
          `offset` rather than asking for more.
        MD;
    }

    private function entityModel(): string
    {
        return <<<'MD'
        # EspoCRM entity model conventions

        ## Attribute naming

        A `link` field named `account` produces two readable attributes:

        - `accountId` — the related record's ID, and what you set when writing
        - `accountName` — the display name, read-only

        Always **write** `accountId`. Writing `accountName` does nothing.

        A `linkMultiple` field named `contacts` produces:

        - `contactsIds` — array of IDs, what you set when writing
        - `contactsNames` — object of id to name, read-only

        ## Assignment and teams

        - `assignedUserId` — the owning user. Required on many entities by default.
        - `teamsIds` — array of team IDs controlling team-level visibility.

        Under an `own` record level a record is visible only when `assignedUserId`
        matches the acting user. Under `team`, only when `teamsIds` intersects the
        acting user's teams. Creating a record without `assignedUserId` under an `own`
        level can produce a record you can no longer read.

        ## Enum fields

        `describe_entity` returns the exact `options` array. Values are the stored
        option strings, not translated labels — pass them verbatim.

        ## Before creating

        Call `describe_entity` first. Required fields, enum options and link names vary
        per installation, because entities and fields are customisable.
        MD;
    }

    private function aclLevels(): string
    {
        return <<<'MD'
        # EspoCRM ACL levels

        Every MCP record operation runs as the authenticated user, with that user's
        roles applied. Nothing bypasses ACL.

        ## Record levels

        | Level | Reach |
        |---|---|
        | `all` | every record of the type |
        | `team` | records whose `teamsIds` intersect the user's teams |
        | `own` | records where `assignedUserId` is the user |
        | `no` | none |

        `create` is not a level — it is `yes` or `no`.

        ## Reading a Forbidden response

        - *"No access to 'X'"* — the role grants no access to that scope at all.
        - *"'X' is read-only through MCP"* — the entity is on the MCP write deny-list
          (`mcp.security.deniedWriteEntityTypes`). `User` and `Team` are readable so
          that records can be assigned, but never writable through MCP. Manage them in
          the EspoCRM UI.
        - *"'X' is not accessible through MCP"* — the entity is fully denied
          (`mcp.security.deniedEntityTypes`). Auth and job internals sit here.

        These deny-lists are deliberate and apply even to administrators, because an
        administrator bypasses ordinary ACL. They are not a misconfiguration to work
        around.

        ## When a write fails but a read succeeded

        The role most likely grants `read` at a wider level than `edit`. Call
        `list_entity_types` to see the effective per-action levels.
        MD;
    }
}
