<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/core/allpest/ApDose.php';

final class ApDoseTest extends TestCase
{
    public function testVolumeIsHeightTimesDepthTimesLength(): void
    {
        $this->assertEqualsWithDelta(75.0, ApDose::volume(2.5, 2.5, 12.0), 0.0001);
    }

    public function testDoseIsVolumeTimesDoseRate(): void
    {
        $this->assertEqualsWithDelta(3600.0, ApDose::doseGrams(75, 48), 0.0001);
    }

    public function testChloropicrinMixDividesBy098(): void
    {
        $this->assertEqualsWithDelta(3673.47, ApDose::doseGrams(75, 48, 0.98), 0.01);
    }

    public function testRoundUpToDispensingIncrement(): void
    {
        $this->assertEqualsWithDelta(3700.0, ApDose::roundUpToIncrement(3673.47, 100), 0.0001);
        $this->assertEqualsWithDelta(4080.0, ApDose::roundUpToIncrement(3673.47, 680), 0.0001);
        $this->assertEqualsWithDelta(3600.0, ApDose::roundUpToIncrement(3600.0, 100), 0.0001);
    }

    public function testCompensationAddsEightPerFiveDegreesOrPartBelowReference(): void
    {
        $this->assertEqualsWithDelta(64.0, ApDose::compensatedDoseRate(48, 15, 21, true), 0.0001);
        $this->assertEqualsWithDelta(56.0, ApDose::compensatedDoseRate(48, 16, 21, true), 0.0001);
        $this->assertEqualsWithDelta(56.0, ApDose::compensatedDoseRate(48, 20.5, 21, true), 0.0001);
        $this->assertEqualsWithDelta(48.0, ApDose::compensatedDoseRate(48, 21, 21, true), 0.0001);
        $this->assertEqualsWithDelta(48.0, ApDose::compensatedDoseRate(48, 25, 21, true), 0.0001);
    }

    public function testCompensationNotAppliedWhenScheduleDoesNotAllowIt(): void
    {
        $this->assertEqualsWithDelta(48.0, ApDose::compensatedDoseRate(48, 15, 21, false), 0.0001);
    }

    public function testTopUpAmount(): void
    {
        $this->assertEqualsWithDelta(296.0, ApDose::topUpGrams(40, 19.4, 12.0), 0.0001);
        $this->assertEqualsWithDelta(302.04, ApDose::topUpGrams(40, 19.4, 12.0, 0.98), 0.01);
    }

    public function testAdditionalDoseBeforeStartPoint(): void
    {
        $this->assertEqualsWithDelta(480.0, ApDose::additionalDoseBeforeStart(40, 48, 36), 0.0001);
    }

    public function testZeroDimensionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApDose::volume(0, 2, 2);
    }

    public function testNegativeVolumeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApDose::doseGrams(-1, 48);
    }

    public function testZeroIncrementIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApDose::roundUpToIncrement(10, 0);
    }

    public function testMbFractionOutOfRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApDose::doseGrams(75, 48, 0.0);
    }
}
