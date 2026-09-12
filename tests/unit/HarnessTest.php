<?php

namespace Espo\Modules\EspoMcp\Tests\unit;

use PHPUnit\Framework\TestCase;

class HarnessTest extends TestCase
{
    public function testHarnessRuns(): void
    {
        $this->assertTrue(true);
    }

    public function testPhpVersionMeetsFloor(): void
    {
        $this->assertTrue(
            version_compare(PHP_VERSION, '8.3.0', '>='),
            'EspoMcp requires PHP 8.3 or newer.'
        );
    }
}
