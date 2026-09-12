<?php

namespace Espo\Modules\EspoMcp\Tests\unit\Setup;

use Espo\Modules\EspoMcp\Tools\Mcp\Setup\AccessPreset;
use Espo\Modules\EspoMcp\Tools\Mcp\Setup\SetupService;
use PHPUnit\Framework\TestCase;

/**
 * SetupService itself is Espo-dependent and deliberately not unit-tested.
 * These are its pure helpers: role-name bookkeeping and the warning text
 * that tells an administrator what actually happened.
 */
class SetupServiceTest extends TestCase
{
    public function testRolesThisModuleCreatesAreRecognised(): void
    {
        $this->assertTrue(
            SetupService::isManagedRoleName(SetupService::MANAGED_ROLE_PREFIX . 'sales-assistant')
        );
        $this->assertTrue(
            SetupService::isManagedRoleName(SetupService::MANAGED_ROLE_PREFIX . 'anything')
        );
    }

    public function testUnrelatedRolesAreNotManaged(): void
    {
        $this->assertFalse(SetupService::isManagedRoleName('Sales Manager'));
        $this->assertFalse(SetupService::isManagedRoleName('MCP'));
        $this->assertFalse(SetupService::isManagedRoleName('Legacy MCP — sales-assistant'));
    }

    public function testSupersedingAppendsTheSuffix(): void
    {
        $this->assertSame(
            'MCP — sales-assistant (superseded)',
            SetupService::supersededRoleName('MCP — sales-assistant')
        );
    }

    public function testSupersedingIsIdempotent(): void
    {
        $once = SetupService::supersededRoleName('MCP — sales-assistant');

        $this->assertSame($once, SetupService::supersededRoleName($once));
    }

    public function testTeamLevelIsWarnedAbout(): void
    {
        $warnings = SetupService::levelWarnings(AccessPreset::LEVEL_TEAM);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('no teams', $warnings[0]);
        $this->assertStringContainsString('no records', $warnings[0]);
    }

    public function testOtherLevelsCarryNoWarning(): void
    {
        $this->assertSame([], SetupService::levelWarnings(AccessPreset::LEVEL_OWN));
        $this->assertSame([], SetupService::levelWarnings(AccessPreset::LEVEL_ALL));
    }

    public function testWarningIdentifiesTheLiveRoleById(): void
    {
        $warning = SetupService::provisionWarning('MCP — sales-assistant', 'abc123', false);

        $this->assertStringContainsString('MCP — sales-assistant', $warning);
        $this->assertStringContainsString('abc123', $warning);
    }

    public function testWarningIsSilentAboutReactivationWhenThereWasNone(): void
    {
        $warning = SetupService::provisionWarning('MCP — sales-assistant', 'abc123', false);

        $this->assertStringNotContainsStringIgnoringCase('reactivated', $warning);
    }

    public function testWarningStatesAReactivationPlainly(): void
    {
        $warning = SetupService::provisionWarning('MCP — sales-assistant', 'abc123', true);

        $this->assertStringContainsStringIgnoringCase('reactivated', $warning);
        $this->assertStringContainsStringIgnoringCase('revoked', $warning);
        $this->assertStringContainsString('new API key', $warning);
        // The one-time-key guidance survives the reactivation prefix.
        $this->assertStringContainsString('abc123', $warning);
    }
}
