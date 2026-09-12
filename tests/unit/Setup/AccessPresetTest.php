<?php

namespace Espo\Modules\EspoMcp\Tests\unit\Setup;

use Espo\Modules\EspoMcp\Tools\Mcp\Setup\AccessPreset;
use PHPUnit\Framework\TestCase;

class AccessPresetTest extends TestCase
{
    public function testAllFourPresetsExist(): void
    {
        $names = AccessPreset::names();

        $this->assertSame(
            ['readonly-analyst', 'sales-assistant', 'support-agent', 'full-operator'],
            $names
        );

        foreach ($names as $name) {
            $this->assertTrue(AccessPreset::exists($name));
        }
    }

    public function testUnknownPresetDoesNotExist(): void
    {
        $this->assertFalse(AccessPreset::exists('god-mode'));
    }

    public function testReadonlyAnalystGivesReadEverywhere(): void
    {
        $this->assertSame(
            AccessPreset::SHAPE_READ,
            AccessPreset::shapeFor('readonly-analyst', 'Account', [])
        );
        $this->assertSame(
            AccessPreset::SHAPE_READ,
            AccessPreset::shapeFor('readonly-analyst', 'CustomThing', [])
        );
    }

    public function testSalesAssistantWritesCoreAndReadsTheRest(): void
    {
        $this->assertSame(
            AccessPreset::SHAPE_READWRITE,
            AccessPreset::shapeFor('sales-assistant', 'Opportunity', [])
        );
        $this->assertSame(
            AccessPreset::SHAPE_READ,
            AccessPreset::shapeFor('sales-assistant', 'Document', [])
        );
    }

    public function testSupportAgentCoreSetDiffersFromSales(): void
    {
        $this->assertSame(
            AccessPreset::SHAPE_READWRITE,
            AccessPreset::shapeFor('support-agent', 'Case', [])
        );
        $this->assertSame(
            AccessPreset::SHAPE_READ,
            AccessPreset::shapeFor('support-agent', 'Opportunity', [])
        );
    }

    public function testFullOperatorGivesFullEverywhere(): void
    {
        $this->assertSame(
            AccessPreset::SHAPE_FULL,
            AccessPreset::shapeFor('full-operator', 'AnythingAtAll', [])
        );
    }

    public function testOverrideWins(): void
    {
        $this->assertSame(
            AccessPreset::SHAPE_NONE,
            AccessPreset::shapeFor('full-operator', 'Document', ['Document' => 'none'])
        );
        $this->assertSame(
            AccessPreset::SHAPE_READWRITE,
            AccessPreset::shapeFor('readonly-analyst', 'Task', ['Task' => 'readwrite'])
        );
    }

    public function testInvalidOverrideShapeIsIgnored(): void
    {
        // Fail closed: a bad shape falls back to the preset, never to something wider.
        $this->assertSame(
            AccessPreset::SHAPE_READ,
            AccessPreset::shapeFor('readonly-analyst', 'Task', ['Task' => 'superuser'])
        );
    }

    public function testEveryPresetDisablesExportAndMassUpdate(): void
    {
        foreach (AccessPreset::names() as $name) {
            $permissions = AccessPreset::permissions($name);

            $this->assertSame('no', $permissions['exportPermission'], $name);
            $this->assertSame('no', $permissions['massUpdatePermission'], $name);
            $this->assertSame('no', $permissions['dataPrivacyPermission'], $name);
        }
    }

    public function testLevelValidation(): void
    {
        $this->assertTrue(AccessPreset::isValidLevel('team'));
        $this->assertFalse(AccessPreset::isValidLevel('everything'));
    }

    public function testShapeValidation(): void
    {
        $this->assertTrue(AccessPreset::isValidShape('readwrite'));
        $this->assertFalse(AccessPreset::isValidShape('rw'));
    }
}
