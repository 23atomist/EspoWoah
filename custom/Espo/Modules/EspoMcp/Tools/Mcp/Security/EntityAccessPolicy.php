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

namespace Espo\Modules\EspoMcp\Tools\Mcp\Security;

/**
 * Entity-type deny-lists for the generic record tools.
 *
 * These exist because admins bypass ACL entirely. Without them, an
 * admin-authenticated MCP session can create an admin user or reset a
 * password through create_record/update_record — reachable by prompt
 * injection, since CRM records carry attacker-supplied text.
 *
 * Pure logic: no Espo dependencies, so it is unit-testable standalone.
 */
final class EntityAccessPolicy
{
    public const string OPERATION_READ = 'read';
    public const string OPERATION_WRITE = 'write';

    /**
     * Never reachable by any MCP tool, in any operation.
     *
     * @var string[]
     */
    public const array DEFAULT_DENIED = [
        'AuthToken',
        'AuthLogRecord',
        'PasswordChangeRequest',
        'ActionHistoryRecord',
        'AppSecret',
        'Extension',
        'Job',
        'ScheduledJob',
        'AuthenticationProvider',
    ];

    /**
     * Readable (you need user and team IDs to assign records) but never
     * writable through MCP.
     *
     * Role is listed for defence in depth only: its scope is not `object`,
     * so RecordExecutor already rejects it. The entry guards against a
     * future EspoCRM version flipping that flag.
     *
     * @var string[]
     */
    public const array DEFAULT_DENIED_WRITE = [
        'User',
        'Team',
        'Role',
        'Portal',
        'PortalRole',
    ];

    /**
     * @param string[] $deniedEntityTypes
     * @param string[] $deniedWriteEntityTypes
     */
    private function __construct(
        private array $deniedEntityTypes,
        private array $deniedWriteEntityTypes,
    ) {}

    /**
     * Build from the `mcp.security` config subtree.
     *
     * Config is additive only — the shipped defaults are always unioned in,
     * so a short or empty operator list can never re-enable a denied type.
     *
     * `Espo\Core\Utils\Config::get()` may hand back a subtree as either an
     * array or a `stdClass`, depending on how it was stored. Both shapes are
     * normalised to an array here, at the single boundary every caller
     * funnels through, so a config subtree that happens to arrive as an
     * object is never silently dropped.
     *
     * @param array<string, mixed>|object|null $config
     */
    public static function fromConfig(array|object|null $config): self
    {
        if (is_object($config)) {
            $config = (array) $config;
        }

        $extraDenied = self::stringList($config['deniedEntityTypes'] ?? null);
        $extraDeniedWrite = self::stringList($config['deniedWriteEntityTypes'] ?? null);

        return new self(
            array_values(array_unique([...self::DEFAULT_DENIED, ...$extraDenied])),
            array_values(array_unique([...self::DEFAULT_DENIED_WRITE, ...$extraDeniedWrite])),
        );
    }

    /**
     * @return string[]
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_filter($value, static fn($item) => is_string($item) && $item !== '')
        );
    }

    public function isDenied(string $entityType, string $operation): bool
    {
        if (in_array($entityType, $this->deniedEntityTypes, true)) {
            return true;
        }

        // Fail closed: anything that is not explicitly a read is treated as a write.
        if ($operation === self::OPERATION_READ) {
            return false;
        }

        return in_array($entityType, $this->deniedWriteEntityTypes, true);
    }

    /**
     * A message naming the list responsible, so a denial reads as policy
     * rather than as a bug.
     */
    public function denialReason(string $entityType, string $operation): ?string
    {
        if (in_array($entityType, $this->deniedEntityTypes, true)) {
            return "MCP: '$entityType' is not accessible through MCP " .
                "(mcp.security.deniedEntityTypes).";
        }

        if (
            $operation !== self::OPERATION_READ &&
            in_array($entityType, $this->deniedWriteEntityTypes, true)
        ) {
            return "MCP: '$entityType' is read-only through MCP " .
                "(mcp.security.deniedWriteEntityTypes). Manage it in the EspoCRM UI.";
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function deniedEntityTypes(): array
    {
        return $this->deniedEntityTypes;
    }

    /**
     * @return string[]
     */
    public function deniedWriteEntityTypes(): array
    {
        return $this->deniedWriteEntityTypes;
    }
}
