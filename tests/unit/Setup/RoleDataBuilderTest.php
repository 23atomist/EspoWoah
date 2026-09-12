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
            // edit's level list is PRESENT but EMPTY — must mean "no level
            // is permitted for edit", clamping to 'no', never falling
            // through to the unrestricted default.
            'EmptyEditLevels' => [
                'entity' => true,
                'object' => true,
                'aclActionLevelListMap' => ['edit' => []],
            ],
            // aclActionList is PRESENT but EMPTY — must mean "no action is
            // permitted at all", collapsing the whole scope to `false`.
            'EmptyActionList' => [
                'entity' => true,
                'object' => true,
                'aclActionList' => [],
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

    public function testEmptyActionLevelListIsNothingPermittedNotEverything(): void
    {
        // aclActionLevelListMap['edit'] => [] is the natural way to encode
        // "no level is permitted for edit". It must clamp to 'no' — it must
        // NOT fall through to the unrestricted default and grant 'all'
        // just because the requested preset/level is maximally permissive.
        $data = $this->builder()->build('full-operator', 'all', [], $this->scopes());

        $this->assertSame('no', $data['EmptyEditLevels']['edit']);
    }

    public function testEmptyAclActionListDisablesScope(): void
    {
        // aclActionList => [] means no action is permitted at all. The
        // scope must collapse to `false` — the same encoding used for any
        // other disabled scope — not an empty action map.
        $data = $this->builder()->build('full-operator', 'all', [], $this->scopes());

        $this->assertFalse($data['EmptyActionList']);
    }

    public function testUnknownPresetDropsOverridesAndDisablesEverything(): void
    {
        // shapeFor() resolves overrides BEFORE it looks the preset up, so an
        // override alone must not be able to grant access under a preset
        // name that does not exist.
        $data = $this->builder()->build(
            'does-not-exist',
            'all',
            ['Account' => 'full'],
            $this->scopes()
        );

        $this->assertFalse($data['Account']);
    }

    /**
     * Property test for the invariant this class exists to guarantee:
     * clamping never widens. For every non-'create' action actually
     * emitted, its rank must never exceed the lesser of (a) the requested
     * record level's rank and (b) the highest rank the scope itself
     * declares as permitted (with an implicit floor of 'no', since clamp()
     * always has 'no' available as a fallback even when the scope's
     * declared list doesn't literally contain it).
     *
     * `testEveryPresetAndLevelProducesValidLevels` only checks membership
     * in {no, own, team, all} — it would pass even if every level were
     * hardcoded to 'all'. This test checks the actual bound.
     */
    public function testClampNeverExceedsRecordLevelOrScopeCeiling(): void
    {
        // Local rank map, independent of RoleDataBuilder::LEVEL_RANK.
        $rank = ['no' => 0, 'own' => 1, 'team' => 2, 'all' => 3];

        $presets = ['readonly-analyst', 'sales-assistant', 'support-agent', 'full-operator'];
        $scopes = $this->scopes();

        foreach ($presets as $preset) {
            foreach (['own', 'team', 'all'] as $recordLevel) {
                $data = $this->builder()->build($preset, $recordLevel, [], $scopes);

                foreach ($data as $entityType => $actions) {
                    if ($actions === false) {
                        continue;
                    }

                    $defs = $scopes[$entityType];

                    foreach ($actions as $action => $value) {
                        if ($action === 'create') {
                            continue;
                        }

                        // The floor is always 0 ('no'): clamp() can always
                        // fall back to 'no' even when the scope's declared
                        // level list doesn't literally include it.
                        $scopeCeilingRank = 0;

                        foreach ($this->declaredLevelsForTest($defs, $action) as $level) {
                            if (!array_key_exists($level, $rank)) {
                                continue;
                            }

                            $scopeCeilingRank = max($scopeCeilingRank, $rank[$level]);
                        }

                        $expectedCeiling = min($rank[$recordLevel], $scopeCeilingRank);

                        $this->assertLessThanOrEqual(
                            $expectedCeiling,
                            $rank[$value],
                            "$preset/$recordLevel/$entityType/$action produced '$value', " .
                                "exceeding the allowed ceiling of rank $expectedCeiling"
                        );
                    }
                }
            }
        }
    }

    /**
     * Independent restatement — from the raw fixture metadata and the
     * documented resolution order (per-action map first even if empty,
     * then the scope-wide list even if empty, else the full default set)
     * — of which levels a scope declares as permitted for an action. This
     * is the published algorithm contract, not a reach into
     * RoleDataBuilder's private methods.
     *
     * @param array<string, mixed> $defs
     * @return string[]
     */
    private function declaredLevelsForTest(array $defs, string $action): array
    {
        $map = $defs['aclActionLevelListMap'] ?? null;

        if (is_array($map) && array_key_exists($action, $map) && is_array($map[$action])) {
            return $map[$action];
        }

        $list = $defs['aclLevelList'] ?? null;

        if (is_array($list)) {
            return $list;
        }

        return ['all', 'team', 'own', 'no'];
    }
}
