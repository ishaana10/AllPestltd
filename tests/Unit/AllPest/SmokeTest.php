<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPasses(): void
    {
        $this->assertSame(2, 1 + 1);
    }

    public function testFailsOnPurpose(): void
    {
        if (getenv('AP_SMOKE_FAIL') === '1') {
            $this->assertSame(1, 2);
        }
        $this->assertTrue(true);
    }
}
