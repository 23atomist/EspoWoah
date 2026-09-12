<?php

namespace Espo\Modules\EspoMcp\Tests\unit\Security;

use Espo\Modules\EspoMcp\Tools\Mcp\Security\EntityAccessPolicy;
use PHPUnit\Framework\TestCase;

class EntityAccessPolicyTest extends TestCase
{
    private function policy(array|object|null $config = null): EntityAccessPolicy
    {
        return EntityAccessPolicy::fromConfig($config);
    }

    public function testUserIsReadableButNotWritable(): void
    {
        $policy = $this->policy();

        $this->assertFalse($policy->isDenied('User', EntityAccessPolicy::OPERATION_READ));
        $this->assertTrue($policy->isDenied('User', EntityAccessPolicy::OPERATION_WRITE));
    }

    public function testTeamIsReadableButNotWritable(): void
    {
        $policy = $this->policy();

        $this->assertFalse($policy->isDenied('Team', EntityAccessPolicy::OPERATION_READ));
        $this->assertTrue($policy->isDenied('Team', EntityAccessPolicy::OPERATION_WRITE));
    }

    public function testAuthTokenIsFullyDenied(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isDenied('AuthToken', EntityAccessPolicy::OPERATION_READ));
        $this->assertTrue($policy->isDenied('AuthToken', EntityAccessPolicy::OPERATION_WRITE));
    }

    public function testOrdinaryEntityIsAllowed(): void
    {
        $policy = $this->policy();

        $this->assertFalse($policy->isDenied('Account', EntityAccessPolicy::OPERATION_READ));
        $this->assertFalse($policy->isDenied('Account', EntityAccessPolicy::OPERATION_WRITE));
    }

    public function testConfigCanExtendDenyList(): void
    {
        $policy = $this->policy(['deniedEntityTypes' => ['Invoice']]);

        $this->assertTrue($policy->isDenied('Invoice', EntityAccessPolicy::OPERATION_READ));
    }

    public function testConfigCannotShrinkDenyListBelowDefaults(): void
    {
        // An operator supplying a short list must not thereby re-enable AuthToken.
        $policy = $this->policy([
            'deniedEntityTypes' => ['Invoice'],
            'deniedWriteEntityTypes' => [],
        ]);

        $this->assertTrue($policy->isDenied('AuthToken', EntityAccessPolicy::OPERATION_READ));
        $this->assertTrue($policy->isDenied('User', EntityAccessPolicy::OPERATION_WRITE));
    }

    public function testDenialReasonNamesTheList(): void
    {
        $reason = $this->policy()->denialReason('User', EntityAccessPolicy::OPERATION_WRITE);

        $this->assertIsString($reason);
        $this->assertStringContainsString('User', $reason);
        $this->assertStringContainsString('mcp.security.deniedWriteEntityTypes', $reason);
    }

    public function testDenialReasonIsNullWhenAllowed(): void
    {
        $this->assertNull(
            $this->policy()->denialReason('Account', EntityAccessPolicy::OPERATION_READ)
        );
    }

    public function testUnknownOperationIsTreatedAsWrite(): void
    {
        // Fail closed: an unrecognised operation must get the stricter treatment.
        $this->assertTrue($this->policy()->isDenied('User', 'something-else'));
    }

    public function testGettersReturnDefaultsPlusOperatorAdditions(): void
    {
        $policy = $this->policy([
            'deniedEntityTypes' => ['Invoice'],
            'deniedWriteEntityTypes' => ['Ledger'],
        ]);

        $denied = $policy->deniedEntityTypes();
        $deniedWrite = $policy->deniedWriteEntityTypes();

        $this->assertContains('AuthToken', $denied);
        $this->assertContains('Invoice', $denied);
        $this->assertContains('User', $deniedWrite);
        $this->assertContains('Ledger', $deniedWrite);
    }

    public function testConfigCanExtendWriteDenyList(): void
    {
        $policy = $this->policy(['deniedWriteEntityTypes' => ['Invoice']]);

        $this->assertTrue($policy->isDenied('Invoice', EntityAccessPolicy::OPERATION_WRITE));
        $this->assertFalse($policy->isDenied('Invoice', EntityAccessPolicy::OPERATION_READ));
    }

    public function testConfigCanExtendDenyListWhenSuppliedAsObject(): void
    {
        // Espo\Core\Utils\Config::get() can hand back a config subtree as a
        // stdClass rather than an array. An operator addition supplied that
        // way must still be honoured, not silently dropped.
        $policy = $this->policy((object) ['deniedEntityTypes' => ['Invoice']]);

        $this->assertTrue($policy->isDenied('Invoice', EntityAccessPolicy::OPERATION_READ));
    }

    public function testDefaultsStillApplyWhenConfigIsAnObject(): void
    {
        // Additive-only must hold on the object path too: an object-shaped
        // config must not be able to shrink the deny list below defaults.
        $policy = $this->policy((object) ['deniedEntityTypes' => ['Invoice']]);

        $this->assertTrue($policy->isDenied('AuthToken', EntityAccessPolicy::OPERATION_READ));
        $this->assertTrue($policy->isDenied('User', EntityAccessPolicy::OPERATION_WRITE));
    }
}
