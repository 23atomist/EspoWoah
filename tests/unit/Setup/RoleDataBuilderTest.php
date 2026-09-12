<?php

namespace Espo\Modules\EspoMcp\Tests\unit\Setup;

use Espo\Modules\EspoMcp\Tools\Mcp\Security\EntityAccessPolicy;
use Espo\Modules\EspoMcp\Tools\Mcp\Setup\RoleDataBuilder;
use PHPUnit\Framework\TestCase;

class RoleDataBuilderTest extends TestCase
{
    private function builder(): RoleDataBuilder
    {
        return new RoleDataBuilder(EntityAccessPolicy::fromConfig(null));
    }

    /**
     * A minimal metadata 'scopes' subtree in the shape EspoCRM produces.
     * The User and Team entries mirror real EspoCRM metadata.
     */
    private function scopes(): array
    {
        return [
            'Account' => ['entity' => true, 'object' => true],
            'Opportunity' => ['entity' => true, 'object' => true],
            'Document' => ['entity' => true, 'object' => true],
            // Real User scope: no create/delete, edit capped at own.
            'User' => [
                'entity' => true,
                'object' => true,
                'aclActionList' => ['read', 'edit'],
                'aclActionLevelListMap' => ['edit' => ['own', 'no']],
            ],
            // Real Team scope: uses aclLevelList, not the map.
            'Team' => [
                'entity' => true,
                'object' => true,
                'aclActionList' => ['read'],
                'aclLevelList' => ['all', 'team', 'no'],
            ],
            // Not an object scope — must be skipped entirely.
            'Role' => ['entity' => true, 'object' => false],
            // Fully denied by policy.
            'AuthToken' => ['entity' => true, 'object' => true],
            // Writable scope that caps edit at 'own' — used to isolate the
            // clamping rule from the deny-list rule (see testClampingNeverWidens).
            'CappedThing' => [
                'entity' => true,
                'object' => true,
                'aclActionLevelListMap' => ['edit' => ['own', 'no']],
            ],
        ];
    }

    public function testSalesCoreEntityGetsCreateAndEdit(): void
    {
        $data = $this->builder()->build('sales-assistant', 'team', [], $this->scopes());

        $this->assertSame('yes', $data['Opportunity']['create']);
        $this->assertSame('team', $data['Opportunity']['read']);
        $this->assertSame('team', $data['Opportunity']['edit']);
        $this->assertSame('no', $data['Opportunity']['delete']);
    }

    public function testSalesNonCoreEntityIsReadOnly(): void
    {
        $data = $this->builder()->build('sales-assistant', 'team', [], $this->scopes());

        $this->assertSame('no', $data['Document']['create']);
        $this->assertSame('team', $data['Document']['read']);
        $this->assertSame('no', $data['Document']['edit']);
    }

    public function testDeleteIsOffExceptFullOperator(): void
    {
        $sales = $this->builder()->build('sales-assistant', 'all', [], $this->scopes());
        $full = $this->builder()->build('full-operator', 'all', [], $this->scopes());

        $this->assertSame('no', $sales['Account']['delete']);
        $this->assertSame('all', $full['Account']['delete']);
    }

    public function testClampingNeverWidens(): void
    {
        // CappedThing is writable, so clamping — not the deny-list — is the
        // operative rule here. Requesting 'all' must land on 'own', the most
        // permissive level the scope actually offers.
        $data = $this->builder()->build('full-operator', 'all', [], $this->scopes());

        $this->assertSame('own', $data['CappedThing']['edit']);
    }

    public function testActionsAbsentFromAclActionListAreOmitted(): void
    {
        $data = $this->builder()->build('full-operator', 'all', [], $this->scopes());

        $this->assertArrayNotHasKey('create', $data['User']);
        $this->assertArrayNotHasKey('delete', $data['User']);
        $this->assertArrayHasKey('read', $data['User']);
    }

    public function testAclLevelListClampsDownwardNotUpward(): void
    {
        // Team offers all/team/no. Requesting 'own' must clamp DOWN to 'no',
        // never up to 'team'.
        $data = $this->builder()->build('full-operator', 'own', [], $this->scopes());

        $this->assertSame('no', $data['Team']['read']);
    }

    public function testAclLevelListHonoursTheRequestedLevelWhenOffered(): void
    {
        $data = $this->builder()->build('full-operator', 'all', [], $this->scopes());

        $this->assertSame('all', $data['Team']['read']);
    }

    public function testNonObjectScopeIsSkipped(): void
    {
        $data = $this->builder()->build('full-operator', 'all', [], $this->scopes());

        $this->assertArrayNotHasKey('Role', $data);
    }

    public function testDeniedScopeIsDisabled(): void
    {
        $data = $this->builder()->build('full-operator', 'all', [], $this->scopes());

        $this->assertFalse($data['AuthToken']);
    }

    public function testWriteDeniedScopeIsReadOnlyEvenUnderFullOperator(): void
    {
        $data = $this->builder()->build('full-operator', 'all', [], $this->scopes());

        $this->assertSame('all', $data['User']['read']);
        $this->assertSame('no', $data['User']['edit']);
    }

    public function testOverrideNoneDisablesScope(): void
    {
        $data = $this->builder()->build(
            'full-operator',
            'all',
            ['Document' => 'none'],
            $this->scopes()
        );

        $this->assertFalse($data['Document']);
    }

    public function testReadonlyAnalystWritesNothing(): void
    {
        $data = $this->builder()->build('readonly-analyst', 'all', [], $this->scopes());

        foreach (['Account', 'Opportunity', 'Document'] as $entityType) {
            $this->assertSame('no', $data[$entityType]['create'], $entityType);
            $this->assertSame('no', $data[$entityType]['edit'], $entityType);
            $this->assertSame('no', $data[$entityType]['delete'], $entityType);
            $this->assertSame('all', $data[$entityType]['read'], $entityType);
        }
    }

    public function testEveryPresetAndLevelProducesValidLevels(): void
    {
        $valid = ['no', 'own', 'team', 'all'];

        $presets = ['readonly-analyst', 'sales-assistant', 'support-agent', 'full-operator'];

        foreach ($presets as $preset) {
            foreach (['own', 'team', 'all'] as $level) {
                $data = $this->builder()->build($preset, $level, [], $this->scopes());

                foreach ($data as $entityType => $actions) {
                    if ($actions === false) {
                        continue;
                    }

                    foreach ($actions as $action => $value) {
                        if ($action === 'create') {
                            $this->assertContains($value, ['yes', 'no'], "$preset/$level/$entityType");

                            continue;
                        }

                        $this->assertContains($value, $valid, "$preset/$level/$entityType/$action");
                    }
                }
            }
        }
    }
}
