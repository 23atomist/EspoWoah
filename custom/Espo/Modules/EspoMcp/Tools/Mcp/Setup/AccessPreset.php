<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Licensed under the MIT License.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Tools\Mcp\Setup;

/**
 * Named access presets and per-entity override shapes.
 *
 * Presets are functions over the live scope list rather than hardcoded
 * entity enumerations, so custom entities are covered automatically.
 *
 * Pure logic: no Espo dependencies.
 */
final class AccessPreset
{
    public const string SHAPE_NONE = 'none';
    public const string SHAPE_READ = 'read';
    public const string SHAPE_READWRITE = 'readwrite';
    public const string SHAPE_FULL = 'full';

    public const string LEVEL_NO = 'no';
    public const string LEVEL_OWN = 'own';
    public const string LEVEL_TEAM = 'team';
    public const string LEVEL_ALL = 'all';

    /** @var string[] */
    private const array SHAPES = [
        self::SHAPE_NONE,
        self::SHAPE_READ,
        self::SHAPE_READWRITE,
        self::SHAPE_FULL,
    ];

    /** @var string[] */
    private const array LEVELS = [
        self::LEVEL_NO,
        self::LEVEL_OWN,
        self::LEVEL_TEAM,
        self::LEVEL_ALL,
    ];

    /**
     * An empty coreSet means "every entity", so coreShape applies globally.
     *
     * @var array<string, array{coreSet: string[], coreShape: string, defaultShape: string}>
     */
    private const array DEFINITIONS = [
        'readonly-analyst' => [
            'coreSet' => [],
            'coreShape' => self::SHAPE_READ,
            'defaultShape' => self::SHAPE_READ,
        ],
        'sales-assistant' => [
            'coreSet' => ['Account', 'Contact', 'Lead', 'Opportunity', 'Task', 'Meeting', 'Call'],
            'coreShape' => self::SHAPE_READWRITE,
            'defaultShape' => self::SHAPE_READ,
        ],
        'support-agent' => [
            'coreSet' => ['Case', 'Contact', 'Account', 'KnowledgeBaseArticle', 'Task', 'Meeting', 'Call'],
            'coreShape' => self::SHAPE_READWRITE,
            'defaultShape' => self::SHAPE_READ,
        ],
        'full-operator' => [
            'coreSet' => [],
            'coreShape' => self::SHAPE_FULL,
            'defaultShape' => self::SHAPE_FULL,
        ],
    ];

    /**
     * Role permission fields. export/massUpdate/dataPrivacy are 'no' on
     * every preset including full-operator: they are the difference between
     * an assistant that edits records and one that can exfiltrate or
     * mass-mutate the database.
     *
     * @var array<string, array<string, string>>
     */
    private const array PERMISSIONS = [
        'readonly-analyst' => [
            'assignmentPermission' => 'no',
            'exportPermission' => 'no',
            'massUpdatePermission' => 'no',
            'dataPrivacyPermission' => 'no',
        ],
        'sales-assistant' => [
            'assignmentPermission' => 'team',
            'exportPermission' => 'no',
            'massUpdatePermission' => 'no',
            'dataPrivacyPermission' => 'no',
        ],
        'support-agent' => [
            'assignmentPermission' => 'team',
            'exportPermission' => 'no',
            'massUpdatePermission' => 'no',
            'dataPrivacyPermission' => 'no',
        ],
        'full-operator' => [
            'assignmentPermission' => 'all',
            'exportPermission' => 'no',
            'massUpdatePermission' => 'no',
            'dataPrivacyPermission' => 'no',
        ],
    ];

    /**
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::DEFINITIONS);
    }

    public static function isValidShape(string $shape): bool
    {
        return in_array($shape, self::SHAPES, true);
    }

    public static function isValidLevel(string $level): bool
    {
        return in_array($level, self::LEVELS, true);
    }

    /**
     * @return array<string, string>
     */
    public static function permissions(string $name): array
    {
        return self::PERMISSIONS[$name] ?? [];
    }

    /**
     * Resolve the access shape for one entity type.
     *
     * A valid override wins outright. An invalid override is ignored and
     * the preset applies — failing closed rather than widening.
     *
     * @param array<string, string> $overrides
     */
    public static function shapeFor(string $preset, string $entityType, array $overrides): string
    {
        $override = $overrides[$entityType] ?? null;

        if (is_string($override) && self::isValidShape($override)) {
            return $override;
        }

        $definition = self::DEFINITIONS[$preset] ?? null;

        if ($definition === null) {
            return self::SHAPE_NONE;
        }

        if ($definition['coreSet'] === [] || in_array($entityType, $definition['coreSet'], true)) {
            return $definition['coreShape'];
        }

        return $definition['defaultShape'];
    }
}
