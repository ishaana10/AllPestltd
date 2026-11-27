<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/core/allpest/ApResult.php';

final class ApResultTest extends TestCase
{
    private static function five(array $failing = []): array
    {
        $out = [];
        foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $id) {
            $out[] = ['id' => $id, 'meetsStandard' => !in_array($id, $failing, true)];
        }
        return $out;
    }

    public function testAllContainersMeetingTheStandardPass(): void
    {
        $r = ApResult::evaluate(self::five(), false, true);
        $this->assertSame('pass', $r['overall']);
        $this->assertSame(['c1' => 'pass', 'c2' => 'pass', 'c3' => 'pass', 'c4' => 'pass', 'c5' => 'pass'], $r['containers']);
    }

    public function testOneFailedContainerWithNoTopUpPossibleIsAPartialPass(): void
    {
        $r = ApResult::evaluate(self::five(['c3']), false, true);
        $this->assertSame('partial', $r['overall']);
        $this->assertSame('fail', $r['containers']['c3']);
        $this->assertSame('pass', $r['containers']['c1']);
        $this->assertSame('pass', $r['containers']['c5']);
    }

    public function testTwoFailedContainersFailEverything(): void
    {
        $r = ApResult::evaluate(self::five(['c2', 'c3']), false, true);
        $this->assertSame('fail', $r['overall']);
        $this->assertSame(['fail', 'fail', 'fail', 'fail', 'fail'], array_values($r['containers']));
    }

    public function testOneFailedContainerFailsAllWhileATopUpIsStillPossible(): void
    {
        $r = ApResult::evaluate(self::five(['c3']), true, true);
        $this->assertSame('fail', $r['overall']);
        $this->assertSame('fail', $r['containers']['c1']);
    }

    public function testPartialPassOnlyAppliesWhenTheTargetIsInsideTheContainers(): void
    {
        $r = ApResult::evaluate(self::five(['c3']), false, false);
        $this->assertSame('fail', $r['overall']);
    }

    public function testASingleContainerThatFailsFailsTheTreatment(): void
    {
        $r = ApResult::evaluate([['id' => 'only', 'meetsStandard' => false]], false, true);
        $this->assertSame('fail', $r['overall']);
        $this->assertSame(['only' => 'fail'], $r['containers']);
    }

    public function testNoContainersIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApResult::evaluate([], false, true);
    }

    public function testEndTopUpAllowedWithinTheBand(): void
    {
        $r = ApResult::endOfExposureTopUp(true, false, 12.0, 14.4, 9.4);
        $this->assertTrue($r['allowed']);
        $this->assertSame(4.0, $r['extensionHours']);
    }

    public function testEndTopUpNotAllowedBelowTheMinimumToAllowTopUp(): void
    {
        $this->assertFalse(ApResult::endOfExposureTopUp(true, false, 9.0, 14.4, 9.4)['allowed']);
    }

    public function testEndTopUpOnlyOnce(): void
    {
        $this->assertFalse(ApResult::endOfExposureTopUp(true, true, 12.0, 14.4, 9.4)['allowed']);
    }

    public function testEndTopUpNotAllowedWhenScheduleProhibitsIt(): void
    {
        $this->assertFalse(ApResult::endOfExposureTopUp(false, false, 12.0, 14.4, 9.4)['allowed']);
    }

    public function testEndTopUpUsesTheScheduleExtensionWhenGiven(): void
    {
        $r = ApResult::endOfExposureTopUp(true, false, 12.0, 14.4, 9.4, 6.0);
        $this->assertSame(6.0, $r['extensionHours']);
    }

    public function testEndTopUpNotNeededWhenAlreadyAtStandard(): void
    {
        $this->assertFalse(ApResult::endOfExposureTopUp(true, false, 14.4, 14.4, 9.4)['allowed']);
    }
}
