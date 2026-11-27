<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/core/allpest/ApReadings.php';

final class ApReadingsTest extends TestCase
{
    public function testParseReadingAcceptsPlainDecimals(): void
    {
        $this->assertSame(4.3, ApReadings::parseReading('4.3'));
        $this->assertSame(0.0, ApReadings::parseReading('0'));
        $this->assertSame(48.0, ApReadings::parseReading(' 48 '));
    }

    /** @dataProvider-free: each bad entry must throw (Review Focus 1). */
    public function testParseReadingRejectsBadEntries(): void
    {
        foreach (['', '>4', '4 ppm', '-1', '4,3', 'abc', '1e3', '4.'] as $bad) {
            $threw = false;
            try {
                ApReadings::parseReading($bad);
            } catch (InvalidArgumentException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "expected '$bad' to be rejected");
        }
    }

    public function testEquilibriumPercentAndRule(): void
    {
        $this->assertEqualsWithDelta(15.0, ApReadings::equilibriumPercent([46, 40]), 0.0001);
        $this->assertTrue(ApReadings::isEquilibrium([46, 40]));
        $this->assertFalse(ApReadings::isEquilibrium([46.1, 40]));
        $this->assertEqualsWithDelta(0.0, ApReadings::equilibriumPercent([40]), 0.0001);
    }

    public function testEquilibriumNeedsAtLeastOneReading(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApReadings::equilibriumPercent([]);
    }

    public function testEvaluateSetFlagsLowLocationsAndTopUpEligibility(): void
    {
        $r = ApReadings::evaluateSet(['A' => 14.4, 'B' => 15.0, 'C' => 14.0], 14.4, 9.4);
        $this->assertFalse($r['allMeet']);
        $this->assertSame(['C'], $r['belowLocations']);
        $this->assertSame(14.0, $r['lowest']);
        $this->assertTrue($r['topUpPossible']);

        $r2 = ApReadings::evaluateSet(['A' => 14.4, 'B' => 15.0, 'C' => 9.0], 14.4, 9.4);
        $this->assertFalse($r2['topUpPossible']);

        $r3 = ApReadings::evaluateSet(['A' => 14.4, 'B' => 15.0], 14.4, 9.4);
        $this->assertTrue($r3['allMeet']);
        $this->assertSame([], $r3['belowLocations']);
    }

    public function testRequiredLocations(): void
    {
        $this->assertSame(1, ApReadings::requiredLocations('general', 29.9));
        $this->assertSame(3, ApReadings::requiredLocations('general', 30));
        $this->assertSame(1, ApReadings::requiredLocations('perishable', 4.9));
        $this->assertSame(3, ApReadings::requiredLocations('perishable', 5));
        $this->assertSame(4, ApReadings::requiredLocations('perishable', 8, 4));
        $this->assertSame(3, ApReadings::requiredLocations('general', 10, 1, true));
    }

    public function testRequiredLocationsRejectsUnknownTypeAndBadVolume(): void
    {
        $threw = 0;
        foreach ([['x', 10], ['general', 0], ['general', -5]] as [$t, $v]) {
            try {
                ApReadings::requiredLocations($t, $v);
            } catch (InvalidArgumentException $e) {
                $threw++;
            }
        }
        $this->assertSame(3, $threw);
    }

    public function testReadingsDue(): void
    {
        $this->assertSame([0.0, 24.0], ApReadings::readingsDue(24));
        $this->assertSame([0.0, 24.0, 48.0], ApReadings::readingsDue(48));
        $this->assertSame([0.0, 24.0, 30.0], ApReadings::readingsDue(30));
        $this->assertSame([0.0, 12.0], ApReadings::readingsDue(12));
    }

    public function testTlv(): void
    {
        $this->assertTrue(ApReadings::tlvOk(5.0));
        $this->assertFalse(ApReadings::tlvOk(5.1));
        $this->assertTrue(ApReadings::tlvOk(0.0));
    }
}
