<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Licensed under the MIT License.
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
     * @param ?array<string, mixed> $config
     */
    public static function fromConfig(?array $config): self
    {
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
