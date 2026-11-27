<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/core/allpest/ApStandards.php';

final class ApStandardsTest extends TestCase
{
    public function testStandardAndTopUpBandMatchTable6ForDose48At24h(): void
    {
        $this->assertEqualsWithDelta(14.4, ApStandards::standardAt(48, 24), 0.001);
        $band = ApStandards::topUpBand(48, 14.4);
        $this->assertEqualsWithDelta(9.4, $band['min'], 0.001);
        $this->assertEqualsWithDelta(19.4, $band['max'], 0.001);
    }

    public function testDose64At2hUsesPlusMinus8(): void
    {
        $this->assertEqualsWithDelta(38.4, ApStandards::standardAt(64, 2), 0.001);
        $band = ApStandards::topUpBand(64, 38.4);
        $this->assertEqualsWithDelta(30.4, $band['min'], 0.001);
        $this->assertEqualsWithDelta(46.4, $band['max'], 0.001);
    }

    public function testDose32At48h(): void
    {
        $this->assertEqualsWithDelta(8.0, ApStandards::standardAt(32, 48), 0.001);
        $band = ApStandards::topUpBand(32, 8.0);
        $this->assertEqualsWithDelta(3.0, $band['min'], 0.001);
        $this->assertEqualsWithDelta(13.0, $band['max'], 0.001);
    }

    public function testDose128At4h(): void
    {
        $this->assertEqualsWithDelta(64.0, ApStandards::standardAt(128, 4), 0.001);
        $band = ApStandards::topUpBand(128, 64.0);
        $this->assertEqualsWithDelta(56.0, $band['min'], 0.001);
        $this->assertEqualsWithDelta(72.0, $band['max'], 0.001);
    }

    public function testPublishedTable3TypoIsNotCopied(): void
    {
        // Table 3 prints 15.1 for dose 40 at 48 h; the formula and Table 6 give 15.0.
        $band = ApStandards::topUpBand(40, ApStandards::standardAt(40, 48));
        $this->assertEqualsWithDelta(15.0, $band['max'], 0.001);
        $this->assertEqualsWithDelta(5.0, $band['min'], 0.001);
    }

    public function testWholeGramInstrumentRoundsUp(): void
    {
        $this->assertEqualsWithDelta(17.54, ApStandards::standardAt(32, 3), 0.001);
        $this->assertSame(18.0, ApStandards::standardAt(32, 3, true));
    }

    public function testReadingBetweenRowsUsesThePrecedingHour(): void
    {
        $this->assertEqualsWithDelta(28.8, ApStandards::standardAt(48, 2.5), 0.001);
        $this->assertEqualsWithDelta(13.99, ApStandards::standardAt(48, 30), 0.001);
    }

    public function testStartPointStandardByTimeSinceInjection(): void
    {
        $this->assertEqualsWithDelta(40.8, ApStandards::startPointStandard(48, 20), 0.001);
        $this->assertEqualsWithDelta(36.0, ApStandards::startPointStandard(48, 45), 0.001);
        $this->assertEqualsWithDelta(33.6, ApStandards::startPointStandard(48, 90), 0.001);
        $this->assertNull(ApStandards::startPointStandard(48, 10));
        $this->assertEqualsWithDelta(38.4, ApStandards::startPointStandard(48, 90, true), 0.001);
    }

    public function testStartPointBoundariesTakeTheStricterStandard(): void
    {
        $this->assertEqualsWithDelta(40.8, ApStandards::startPointStandard(48, 30), 0.001);
        $this->assertEqualsWithDelta(36.0, ApStandards::startPointStandard(48, 60), 0.001);
        $this->assertEqualsWithDelta(33.6, ApStandards::startPointStandard(48, 61), 0.001);
    }

    public function testDoseOutsidePublishedBandRangeIsRejectedForTopUp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApStandards::topUpBand(20, 5.0);
    }
}
