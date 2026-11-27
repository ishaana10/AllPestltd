<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/core/allpest/ApTemperature.php';
require_once dirname(__DIR__, 3) . '/core/allpest/ApForecastClient.php';

final class ApTemperatureTest extends TestCase
{
    private static function at(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s);
    }

    /** 30 hourly points from 2026-12-01 17:00 (Fiji time); the minimum, 17.5, falls at 04:00. */
    private static function hourly(): array
    {
        $pts = [];
        $t = self::at('2026-12-01T17:00:00+12:00');
        for ($i = 0; $i < 30; $i++) {
            $hour = (int)$t->format('G');
            $d = abs($hour - 4);
            $temp = 17.5 + min($d, 24 - $d) * 0.5;
            $pts[] = ['time' => $t->format('Y-m-d\TH:i'), 'temp' => $temp];
            $t = $t->modify('+1 hour');
        }
        return $pts;
    }

    public function testFijiHasNoDaylightSavingInTheTestEnvironment(): void
    {
        $z = new DateTimeZone('Pacific/Fiji');
        $this->assertSame(12 * 3600, $z->getOffset(new DateTime('2026-12-01')));
    }

    public function testWindowRunsFromDoseTimeToExposureEndPlusMargin(): void
    {
        $w = ApTemperature::exposureWindow(self::at('2026-12-01T18:00:00+12:00'), 24);
        $this->assertSame('2026-12-01T18:00:00+12:00', $w['from']->format('c'));
        $this->assertSame('2026-12-02T20:00:00+12:00', $w['to']->format('c'));
    }

    public function testWindowRejectsZeroExposure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApTemperature::exposureWindow(self::at('2026-12-01T18:00:00+12:00'), 0);
    }

    public function testLowestForecastIgnoresPointsOutsideTheWindow(): void
    {
        $pts = self::hourly();
        $pts[] = ['time' => '2026-12-02T23:00', 'temp' => 3.0]; // outside window
        $w = ApTemperature::exposureWindow(self::at('2026-12-01T18:00:00+12:00'), 24);
        $this->assertSame(17.5, ApTemperature::lowestForecast($pts, $w['from'], $w['to']));
    }

    public function testLowestForecastIncludesTheHourContainingTheDoseTime(): void
    {
        $pts = [['time' => '2026-12-01T18:00', 'temp' => 12.0], ['time' => '2026-12-01T19:00', 'temp' => 20.0]];
        $from = self::at('2026-12-01T18:30:00+12:00');
        $to = self::at('2026-12-01T19:30:00+12:00');
        $this->assertSame(12.0, ApTemperature::lowestForecast($pts, $from, $to));
    }

    public function testLowestForecastWithNoPointsInRangeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApTemperature::lowestForecast(self::hourly(), self::at('2027-01-01T00:00:00+12:00'), self::at('2027-01-02T00:00:00+12:00'));
    }

    public function testAmbientBlockedAtTenDegreesOrBelow(): void
    {
        $this->assertFalse(ApTemperature::ambientAllowed(10.0));
        $this->assertTrue(ApTemperature::ambientAllowed(10.1));
        $this->assertFalse(ApTemperature::ambientAllowed(-3.0));
    }

    public function testForecastFreshnessWindowStartsAtMidnightTheDayBefore(): void
    {
        $start = self::at('2026-12-01T18:00:00+12:00');
        $this->assertTrue(ApTemperature::forecastIsFresh(self::at('2026-11-30T00:00:00+12:00'), $start));
        $this->assertTrue(ApTemperature::forecastIsFresh(self::at('2026-12-01T09:00:00+12:00'), $start));
        $this->assertFalse(ApTemperature::forecastIsFresh(self::at('2026-11-29T23:59:00+12:00'), $start));
    }

    public function testCoreTemperatureMustReachTheScheduleMinimum(): void
    {
        $this->assertTrue(ApTemperature::coreTempOk(5.0, 5.0));
        $this->assertFalse(ApTemperature::coreTempOk(4.9, 5.0));
    }

    public function testClientParsesOpenMeteoHourlyAndRecordsSource(): void
    {
        $json = json_encode(['hourly' => ['time' => ['2026-12-01T18:00', '2026-12-01T19:00'], 'temperature_2m' => [21.4, 20.9]]]);
        $seen = '';
        $client = new ApForecastClient(function (string $url) use ($json, &$seen) { $seen = $url; return $json; },
            fn () => new DateTimeImmutable('2026-12-01T09:00:00+00:00'));
        $r = $client->fetch(-17.62, 177.45, self::at('2026-12-01T18:00:00+12:00'), self::at('2026-12-02T20:00:00+12:00'));
        $this->assertSame([['time' => '2026-12-01T18:00', 'temp' => 21.4], ['time' => '2026-12-01T19:00', 'temp' => 20.9]], $r['hourly']);
        $this->assertStringContainsString('open-meteo', $r['source']);
        $this->assertStringContainsString('start_date=2026-12-01', $seen);
        $this->assertStringContainsString('end_date=2026-12-02', $seen);
        $this->assertStringContainsString('Pacific%2FFiji', $seen);
        $this->assertSame('2026-12-01T09:00:00+00:00', $r['retrieved_at']);
    }

    public function testClientTurnsTransportFailureIntoForecastUnavailable(): void
    {
        $client = new ApForecastClient(function (string $url): string { throw new RuntimeException('timeout'); });
        $this->expectException(ApForecastUnavailable::class);
        $client->fetch(-17.62, 177.45, self::at('2026-12-01T18:00:00+12:00'), self::at('2026-12-02T20:00:00+12:00'));
    }

    public function testClientRejectsBadJson(): void
    {
        $client = new ApForecastClient(fn (string $u) => 'not json');
        $this->expectException(ApForecastUnavailable::class);
        $client->fetch(-17.62, 177.45, self::at('2026-12-01T18:00:00+12:00'), self::at('2026-12-02T20:00:00+12:00'));
    }

    public function testClientRejectsImplausibleTemperature(): void
    {
        $json = json_encode(['hourly' => ['time' => ['2026-12-01T18:00'], 'temperature_2m' => [-60.0]]]);
        $client = new ApForecastClient(fn (string $u) => $json);
        $this->expectException(ApForecastUnavailable::class);
        $client->fetch(-17.62, 177.45, self::at('2026-12-01T18:00:00+12:00'), self::at('2026-12-02T20:00:00+12:00'));
    }

    public function testClientRejectsImpossibleCoordinates(): void
    {
        $client = new ApForecastClient(fn (string $u) => '{}');
        $this->expectException(InvalidArgumentException::class);
        $client->fetch(95.0, 177.45, self::at('2026-12-01T18:00:00+12:00'), self::at('2026-12-02T20:00:00+12:00'));
    }
}
