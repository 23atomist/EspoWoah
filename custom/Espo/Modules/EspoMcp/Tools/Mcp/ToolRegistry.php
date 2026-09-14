<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Copyright (C) 2026 Thomas Gallaway
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Tools\Mcp;

/**
 * MCP tool definitions (names, descriptions, input JSON schemas).
 */
class ToolRegistry
{
    /**
     * @param bool $includeSetupTools Setup tools are admin-only. Authorisation
     *        is enforced in SetupService, not by this flag.
     * @return array<int, array<string, mixed>>
     */
    public function getAll(bool $includeSetupTools = false): array
    {
        $tools = [
            $this->definition(
                name: 'mcp_whoami',
                description: 'Get the currently authenticated EspoCRM user (id, name, username, teams, ' .
                    'admin status) and the effective MCP identity source.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'list_entity_types',
                description: 'List all EspoCRM entity types (scopes) the current user can access, ' .
                    'with ACL access levels (read/edit/create/delete) for each. Use this to discover ' .
                    'valid entityType values for other tools, including custom entities.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'describe_entity',
                description: 'Get the schema of an entity type: fields (name, type, required, options), ' .
                    'links/relationships, default sort order, and text-filter fields. ' .
                    'Essential before creating or updating records of an unfamiliar entity type.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) [
                            'type' => 'string',
                            'description' => 'Entity type name, e.g. Contact, Account, Opportunity, Lead, Meeting, Task, Call, Case.',
                        ],
                    ],
                    'required' => ['entityType'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'get_record',
                description: 'Read a single record by entity type and ID. Returns the full record ' .
                    'subject to the current user\'s ACL (fields the user cannot access are omitted).',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'id' => (object) ['type' => 'string'],
                    ],
                    'required' => ['entityType', 'id'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'create_record',
                description: 'Create a record of any entity type. Attributes follow the EspoCRM record ' .
                    'format (e.g. name, emailAddress, assignedUserId, contactsIds for links). ' .
                    'Use describe_entity to learn valid fields. Duplicates are detected per entity config.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'attributes' => (object) [
                            'type' => 'object',
                            'description' => 'Record attributes in EspoCRM payload format.',
                        ],
                    ],
                    'required' => ['entityType', 'attributes'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'update_record',
                description: 'Update a record by ID with a partial attribute payload. ' .
                    'Only provided attributes are changed.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'id' => (object) ['type' => 'string'],
                        'attributes' => (object) ['type' => 'object'],
                    ],
                    'required' => ['entityType', 'id', 'attributes'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'delete_record',
                description: 'Delete a record by entity type and ID.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'id' => (object) ['type' => 'string'],
                    ],
                    'required' => ['entityType', 'id'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'search_records',
                description: 'Search records of any entity type. Supports full-text filter, structured ' .
                    'where-filters (equals, in, contains, after, between, linkedWith, etc. — ' .
                    'same grammar as EspoCRM API), field selection, ordering and pagination.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'textFilter' => (object) [
                            'type' => 'string',
                            'description' => 'Full-text filter across the entity\'s text-filter fields.',
                        ],
                        'where' => (object) [
                            'type' => 'array',
                            'description' => 'EspoCRM where-filter items: [{"type":"equals","attribute":"status","value":"New"}]. ' .
                                'Group with {"type":"and"|"or","value":[...]}.',
                            'items' => (object) ['type' => 'object'],
                        ],
                        'select' => (object) [
                            'type' => 'array',
                            'items' => (object) ['type' => 'string'],
                            'description' => 'Optional field list to return.',
                        ],
                        'orderBy' => (object) ['type' => 'string'],
                        'order' => (object) [
                            'type' => 'string',
                            'enum' => ['asc', 'desc'],
                        ],
                        'offset' => (object) ['type' => 'integer'],
                        'maxSize' => (object) ['type' => 'integer'],
                    ],
                    'required' => ['entityType'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'get_related_records',
                description: 'List records related to a record through a relationship link ' .
                    '(e.g. contacts of an Account, opportunities of a Contact, meetings of a User). ' .
                    'Relationship names come from describe_entity "links".',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'id' => (object) ['type' => 'string'],
                        'link' => (object) [
                            'type' => 'string',
                            'description' => 'Relationship name, e.g. "contacts", "opportunities", "meetings".',
                        ],
                        'textFilter' => (object) ['type' => 'string'],
                        'where' => (object) [
                            'type' => 'array',
                            'items' => (object) ['type' => 'object'],
                        ],
                        'offset' => (object) ['type' => 'integer'],
                        'maxSize' => (object) ['type' => 'integer'],
                    ],
                    'required' => ['entityType', 'id', 'link'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'link_records',
                description: 'Create a relationship between two records ' .
                    '(e.g. link a Contact to an Account via link "contacts").',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'id' => (object) ['type' => 'string'],
                        'link' => (object) ['type' => 'string'],
                        'foreignId' => (object) ['type' => 'string'],
                    ],
                    'required' => ['entityType', 'id', 'link', 'foreignId'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'unlink_records',
                description: 'Remove a relationship between two records.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'id' => (object) ['type' => 'string'],
                        'link' => (object) ['type' => 'string'],
                        'foreignId' => (object) ['type' => 'string'],
                    ],
                    'required' => ['entityType', 'id', 'link', 'foreignId'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'convert_lead',
                description: 'Convert a Lead into Contact / Account / Opportunity records in one operation.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'leadId' => (object) ['type' => 'string'],
                        'createContact' => (object) ['type' => 'boolean'],
                        'createAccount' => (object) ['type' => 'boolean'],
                        'createOpportunity' => (object) ['type' => 'boolean'],
                        'opportunityName' => (object) ['type' => 'string'],
                        'opportunityAmount' => (object) [
                            'type' => 'number',
                            'description' => 'Opportunity amount (converted currency value).',
                        ],
                        'closeDate' => (object) [
                            'type' => 'string',
                            'description' => 'YYYY-MM-DD expected close date.',
                        ],
                        'stage' => (object) [
                            'type' => 'string',
                            'description' => 'Opportunity stage, defaults to Prospecting.',
                        ],
                    ],
                    'required' => ['leadId'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'get_stream',
                description: 'Read the stream (activity feed) of a record: posts, status changes, ' .
                    'created/updated events and emails. Ordered newest-first.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'id' => (object) ['type' => 'string'],
                        'offset' => (object) ['type' => 'integer'],
                        'maxSize' => (object) ['type' => 'integer'],
                    ],
                    'required' => ['entityType', 'id'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'post_to_stream',
                description: 'Post a note to a record\'s stream (activity feed), visible to followers.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'entityType' => (object) ['type' => 'string'],
                        'id' => (object) ['type' => 'string'],
                        'post' => (object) ['type' => 'string'],
                        'isInternal' => (object) [
                            'type' => 'boolean',
                            'description' => 'Restrict visibility to internal teams.',
                        ],
                    ],
                    'required' => ['entityType', 'id', 'post'],
                    'additionalProperties' => false,
                ]
            ),
        ];

        if ($includeSetupTools) {
            $tools = [...$tools, ...$this->setupTools()];
        }

        return $tools;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function setupTools(): array
    {
        $presetSchema = (object) [
            'type' => 'string',
            'enum' => ['readonly-analyst', 'sales-assistant', 'support-agent', 'full-operator'],
            'description' => 'Access profile to base the role on.',
        ];

        $recordLevelSchema = (object) [
            'type' => 'string',
            'enum' => ['own', 'team', 'all'],
            'description' => 'How far record visibility reaches: only assigned records (own), ' .
                'the user\'s teams (team), or everything (all).',
        ];

        $overridesSchema = (object) [
            'type' => 'object',
            'description' => 'Per-entity overrides. Values: none, read, readwrite, full. ' .
                'Example: {"Document": "none", "Task": "readwrite"}.',
        ];

        return [
            $this->definition(
                name: 'mcp_setup_status',
                description: 'Check whether this EspoCRM needs MCP first-run setup. Returns whether ' .
                    'a scoped MCP service user already exists, the available access presets and ' .
                    'record levels, and which entity types are always denied. Admin only. ' .
                    'Call this first.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'mcp_setup_preview',
                description: 'Dry run: return the exact entity-by-permission matrix that would be ' .
                    'created for a given preset, WITHOUT writing anything. Always call this and ' .
                    'show the result to the user for approval before calling mcp_setup_provision.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'preset' => $presetSchema,
                        'recordLevel' => $recordLevelSchema,
                        'overrides' => $overridesSchema,
                    ],
                    'required' => ['preset', 'recordLevel'],
                    'additionalProperties' => false,
                ]
            ),
            $this->definition(
                name: 'mcp_setup_provision',
                description: 'Create the scoped EspoCRM role and API user, and return the API key ' .
                    'ONCE. Requires confirm: true, and requires that the user has seen and approved ' .
                    'the mcp_setup_preview output. The returned key cannot be retrieved again.',
                inputSchema: (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'preset' => $presetSchema,
                        'recordLevel' => $recordLevelSchema,
                        'overrides' => $overridesSchema,
                        'confirm' => (object) [
                            'type' => 'boolean',
                            'description' => 'Must be true. Set it only after the user has ' .
                                'approved the preview output.',
                        ],
                        'replaceExisting' => (object) [
                            'type' => 'boolean',
                            'description' => 'Re-provision an existing MCP service user with new ' .
                                'permissions. Defaults to false.',
                        ],
                    ],
                    'required' => ['preset', 'recordLevel', 'confirm'],
                    'additionalProperties' => false,
                ]
            ),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function definition(string $name, string $description, object $inputSchema): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
        ];
    }
}