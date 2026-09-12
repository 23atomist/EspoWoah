<?php

namespace Espo\Modules\EspoMcp\Tests\unit\Security;

use Espo\Modules\EspoMcp\Tools\Mcp\Security\EntityAccessPolicy;
use PHPUnit\Framework\TestCase;

class EntityAccessPolicyTest extends TestCase
{
    private function policy(?array $config = null): EntityAccessPolicy
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
}
