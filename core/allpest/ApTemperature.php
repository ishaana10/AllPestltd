<?php
declare(strict_types=1);

/** Temperature rules (methodology v3.0 sections 6.1, 6.3). Pure functions. */
final class ApTemperature
{
    public const TZ = 'Pacific/Fiji';
    public const MIN_PLAUSIBLE_C = -30.0;
    public const MAX_PLAUSIBLE_C = 60.0;

    /** Window to take the lowest forecast over: dose time to exposure end plus a safety margin. */
    public static function exposureWindow(DateTimeImmutable $doseTime, float $exposureHours, int $marginHours = 2): array
    {
        if (!is_finite($exposureHours) || $exposureHours <= 0) {
            throw new InvalidArgumentException('Exposure hours must be positive.');
        }
        if ($marginHours < 0) {
            throw new InvalidArgumentException('Margin cannot be negative.');
        }
        $minutes = (int)round(($exposureHours + $marginHours) * 60);
        return ['from' => $doseTime, 'to' => $doseTime->modify('+' . $minutes . ' minutes')];
    }

    /**
     * Lowest forecast temperature for points in [hour containing $from, $to].
     * Each hourly point covers the hour that starts at its time, so the hour containing the dose time counts.
     *
     * @param array<int,array{time:string,temp:float}> $hourly times without an offset are Fiji local time
     */
    public static function lowestForecast(array $hourly, DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        $tz = new DateTimeZone(self::TZ);
        $start = $from->setTimezone($tz)->setTime((int)$from->setTimezone($tz)->format('G'), 0, 0);
        $lowest = null;
        foreach ($hourly as $p) {
            $t = new DateTimeImmutable((string)$p['time'], $tz);
            if ($t < $start || $t > $to) {
                continue;
            }
            $temp = $p['temp'];
            if (!is_numeric($temp) || $temp < self::MIN_PLAUSIBLE_C || $temp > self::MAX_PLAUSIBLE_C) {
                throw new InvalidArgumentException('Implausible forecast temperature: ' . var_export($temp, true));
            }
            $lowest = $lowest === null ? (float)$temp : min($lowest, (float)$temp);
        }
        if ($lowest === null) {
            throw new InvalidArgumentException('No forecast hours fall inside the exposure window.');
        }
        return $lowest;
    }

    /** Ambient fumigation is not allowed when the forecast minimum is 10 C or lower. */
    public static function ambientAllowed(float $lowestMinC): bool
    {
        return $lowestMinC > 10.0;
    }

    /** The forecast must be sourced no earlier than the previous day (from 00:00 Fiji time) before exposure starts. */
    public static function forecastIsFresh(DateTimeImmutable $retrievedAt, DateTimeImmutable $exposureStart): bool
    {
        $local = $exposureStart->setTimezone(new DateTimeZone(self::TZ));
        $earliest = $local->modify('-1 day')->setTime(0, 0, 0);
        return $retrievedAt >= $earliest;
    }

    public static function coreTempOk(float $coreC, float $scheduleMinC): bool
    {
        return $coreC >= $scheduleMinC;
    }
}
