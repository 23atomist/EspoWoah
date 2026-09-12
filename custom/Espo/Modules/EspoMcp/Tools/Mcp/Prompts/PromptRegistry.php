<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Licensed under the MIT License.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Tools\Mcp\Prompts;

/**
 * Workflow prompts.
 *
 * Reference material belongs in resources; these are multi-step
 * procedures with judgement in them, which is what tool descriptions
 * cannot carry. Each is read-only by instruction.
 */
final class PromptRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return [
            [
                'name' => 'pipeline-review',
                'description' => 'Review the open opportunity pipeline: value by stage, ' .
                    'deals that have gone quiet, and what needs attention.',
                'arguments' => [
                    [
                        'name' => 'period',
                        'description' => 'Time window, e.g. "last quarter". Defaults to the last 30 days.',
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'lead-triage',
                'description' => 'Work through new and unassigned leads: summarise, rank the ' .
                    'promising ones, and propose next actions.',
                'arguments' => [
                    [
                        'name' => 'period',
                        'description' => 'Time window. Defaults to the last 30 days.',
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'duplicate-sweep',
                'description' => 'Find probable duplicate records of a given entity type and ' .
                    'report them for review. Proposes, never merges.',
                'arguments' => [
                    [
                        'name' => 'entityType',
                        'description' => 'Entity type to sweep. Defaults to Account.',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, string> $arguments
     * @return ?array{description: string, messages: array<int, array<string, mixed>>}
     */
    public function get(string $name, array $arguments): ?array
    {
        $period = $arguments['period'] ?? 'the last 30 days';
        $entityType = $arguments['entityType'] ?? 'Account';

        $text = match ($name) {
            'pipeline-review' => <<<TXT
            Review my open sales pipeline for {$period}.

            1. Call list_entity_types to confirm you can read Opportunity.
            2. Call describe_entity on Opportunity to learn its stage options and amount field.
            3. Search open opportunities in that window, paging with offset rather than
               raising maxSize.
            4. Report: total value by stage; deals with no activity in the window; deals
               whose close date has passed but are still open.
            5. Propose concrete next actions. Do not modify any record — report only,
               unless I ask you to act.
            TXT,
            'lead-triage' => <<<TXT
            Triage my new leads from {$period}.

            1. Call describe_entity on Lead to learn its status options.
            2. Search leads created in that window that are new or unassigned.
            3. For each: summarise who they are, what they want, and where they came from.
            4. Rank them by how promising they look, and say why.
            5. Propose an owner and a next action for each. Ask before writing anything.
            TXT,
            'duplicate-sweep' => <<<TXT
            Find probable duplicate {$entityType} records.

            1. Call describe_entity on {$entityType} to learn its text-filter fields.
            2. Page through records with search_records, using offset.
            3. Group candidates by near-identical name, shared email domain, or shared
               phone number.
            4. Report each suspected group with the evidence that links them, and which
               record looks like the one to keep.
            5. Do not merge or delete anything. Report only — merges are mine to make.
            TXT,
            default => null,
        };

        if ($text === null) {
            return null;
        }

        $description = '';

        foreach ($this->list() as $entry) {
            if ($entry['name'] === $name) {
                $description = (string) $entry['description'];
            }
        }

        return [
            'description' => $description,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }
}
