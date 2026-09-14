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

namespace Espo\Modules\EspoMcp\Tools\Mcp\Setup;

use Espo\Modules\EspoMcp\Tools\Mcp\Security\EntityAccessPolicy;

/**
 * Builds an EspoCRM Role `data` payload from a preset.
 *
 * Pure logic over a plain `scopes` array: no Espo dependencies, so the
 * whole access-decision surface is unit-testable without an installation.
 *
 * The invariant that matters: clamping NEVER widens. A level the scope
 * does not permit falls back to a more restrictive one, never a more
 * permissive one.
 */
final class RoleDataBuilder
{
    /** @var array<string, int> */
    private const array LEVEL_RANK = [
        AccessPreset::LEVEL_NO => 0,
        AccessPreset::LEVEL_OWN => 1,
        AccessPreset::LEVEL_TEAM => 2,
        AccessPreset::LEVEL_ALL => 3,
    ];

    /** @var string[] */
    private const array DEFAULT_ACTIONS = ['create', 'read', 'edit', 'delete', 'stream'];

    /** @var string[] */
    private const array DEFAULT_LEVELS = [
        AccessPreset::LEVEL_ALL,
        AccessPreset::LEVEL_TEAM,
        AccessPreset::LEVEL_OWN,
        AccessPreset::LEVEL_NO,
    ];

    public function __construct(private EntityAccessPolicy $policy) {}

    /**
     * @param array<string, string> $overrides
     * @param array<string, array<string, mixed>> $scopes metadata 'scopes' subtree
     * @return array<string, array<string, string>|false>
     */
    public function build(string $preset, string $recordLevel, array $overrides, array $scopes): array
    {
        if (!AccessPreset::isValidLevel($recordLevel) || $recordLevel === AccessPreset::LEVEL_NO) {
            $recordLevel = AccessPreset::LEVEL_OWN;
        }

        // An unknown preset alone fails closed via shapeFor()'s null-definition
        // branch, but shapeFor() resolves overrides BEFORE it looks the preset
        // up. Drop overrides for an unknown preset so it cannot be used to grant
        // access under a name that doesn't exist.
        if (!AccessPreset::exists($preset)) {
            $overrides = [];
        }

        $data = [];

        foreach ($scopes as $entityType => $defs) {
            if (!is_array($defs)) {
                continue;
            }

            if (!($defs['entity'] ?? false) || !($defs['object'] ?? false)) {
                continue;
            }

            if ($this->policy->isDenied($entityType, EntityAccessPolicy::OPERATION_READ)) {
                $data[$entityType] = false;

                continue;
            }

            $shape = AccessPreset::shapeFor($preset, $entityType, $overrides);

            if ($shape === AccessPreset::SHAPE_NONE) {
                $data[$entityType] = false;

                continue;
            }

            $writable = !$this->policy->isDenied($entityType, EntityAccessPolicy::OPERATION_WRITE);

            $built = $this->buildScope($defs, $shape, $recordLevel, $writable);

            // A scope with no permitted actions IS a disabled scope: encode it
            // as `false`, the same as any other disabled scope, rather than an
            // empty action map (which would also json_encode as a JSON array,
            // not the object shape EspoCRM's Role `data` expects).
            $data[$entityType] = $built === [] ? false : $built;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $defs
     * @return array<string, string>
     */
    private function buildScope(array $defs, string $shape, string $recordLevel, bool $writable): array
    {
        $desired = $this->desiredLevels($shape, $recordLevel, $writable);

        $scope = [];

        foreach ($this->allowedActions($defs) as $action) {
            if (!array_key_exists($action, $desired)) {
                continue;
            }

            if ($action === 'create') {
                $scope['create'] = $desired['create'] === AccessPreset::LEVEL_NO ? 'no' : 'yes';

                continue;
            }

            $scope[$action] = $this->clamp($desired[$action], $this->allowedLevels($defs, $action));
        }

        return $scope;
    }

    /**
     * Desired level per action, before clamping.
     *
     * @return array<string, string>
     */
    private function desiredLevels(string $shape, string $recordLevel, bool $writable): array
    {
        $no = AccessPreset::LEVEL_NO;

        $canWrite = $writable && in_array(
            $shape,
            [AccessPreset::SHAPE_READWRITE, AccessPreset::SHAPE_FULL],
            true
        );

        $canDelete = $writable && $shape === AccessPreset::SHAPE_FULL;

        return [
            'create' => $canWrite ? $recordLevel : $no,
            'read' => $recordLevel,
            'edit' => $canWrite ? $recordLevel : $no,
            'delete' => $canDelete ? $recordLevel : $no,
            'stream' => $recordLevel,
        ];
    }

    /**
     * `aclActionList` present-but-empty means "no action is permitted" and
     * must be honoured as such — only an ABSENT (or non-array) key falls
     * through to the unrestricted default.
     *
     * @param array<string, mixed> $defs
     * @return string[]
     */
    private function allowedActions(array $defs): array
    {
        $list = $defs['aclActionList'] ?? null;

        if (!is_array($list)) {
            return self::DEFAULT_ACTIONS;
        }

        return array_values(array_filter($list, static fn($item) => is_string($item)));
    }

    /**
     * Per-action level list, then the scope-wide list, then the default.
     *
     * A key that is PRESENT as an array is honoured even when it filters
     * down to an empty list — that is the natural encoding of "no level is
     * permitted for this action" and must clamp to 'no', not fall through
     * to the unrestricted default. Only an ABSENT (or non-array) key falls
     * through.
     *
     * @param array<string, mixed> $defs
     * @return string[]
     */
    private function allowedLevels(array $defs, string $action): array
    {
        $map = $defs['aclActionLevelListMap'] ?? null;

        if (is_array($map) && array_key_exists($action, $map) && is_array($map[$action])) {
            return array_values(array_filter($map[$action], static fn($item) => is_string($item)));
        }

        $list = $defs['aclLevelList'] ?? null;

        if (is_array($list)) {
            return array_values(array_filter($list, static fn($item) => is_string($item)));
        }

        return self::DEFAULT_LEVELS;
    }

    /**
     * The most permissive allowed level that is no more permissive than
     * desired. Falls back to 'no' when nothing qualifies.
     *
     * @param string[] $allowed
     */
    private function clamp(string $desired, array $allowed): string
    {
        $ceiling = self::LEVEL_RANK[$desired] ?? 0;

        $best = AccessPreset::LEVEL_NO;
        $bestRank = -1;

        foreach ($allowed as $level) {
            $rank = self::LEVEL_RANK[$level] ?? null;

            if ($rank === null || $rank > $ceiling) {
                continue;
            }

            if ($rank > $bestRank) {
                $best = $level;
                $bestRank = $rank;
            }
        }

        return $best;
    }
}
