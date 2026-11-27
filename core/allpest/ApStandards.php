<?php
declare(strict_types=1);

/**
 * Methyl bromide fumigation methodology v3.0 standards (Tables 2-6).
 * Pure functions: no database, no I/O.
 */
final class ApStandards
{
    /** Table 4: [hours since start point, retention percent] of the initial dose. */
    private const RETENTION = [
        [0, 100.0], [0.5, 75.0], [1, 70.0], [2, 60.0], [3, 54.8], [4, 50.0], [5, 47.8],
        [6, 45.7], [7, 43.7], [8, 41.8], [9, 40.0], [10, 38.3], [11, 36.6], [12, 35.0],
        [16, 33.35], [20, 31.65], [24, 30.0], [28, 29.15], [32, 28.31], [36, 27.47],
        [40, 26.64], [44, 25.82], [48, 25.0],
    ];

    /** Standard concentration (g/m3) for a reading taken $hoursSinceStartPoint into the exposure. */
    public static function standardAt(float $initialDose, float $hoursSinceStartPoint, bool $wholeGram = false): float
    {
        if ($initialDose <= 0) {
            throw new InvalidArgumentException('Initial dose must be positive.');
        }
        if ($hoursSinceStartPoint < 0) {
            throw new InvalidArgumentException('Hours since start point cannot be negative.');
        }
        $percent = 100.0;
        foreach (self::RETENTION as [$hour, $p]) {
            if ($hoursSinceStartPoint >= $hour) {
                $percent = $p;
            }
        }
        $value = round($initialDose * $percent / 100, 2);
        return $wholeGram ? (float)ceil($value - 1e-9) : $value;
    }

    /** Minimum concentration that still allows a top-up, and the maximum a top-up may reach. */
    public static function topUpBand(float $initialDose, float $standard): array
    {
        if ($initialDose >= 32 && $initialDose <= 56) {
            $step = 5.0;
        } elseif ($initialDose > 56 && $initialDose <= 152) {
            $step = 8.0;
        } else {
            throw new InvalidArgumentException('No published top-up band for an initial dose of ' . $initialDose . ' g/m3.');
        }
        return ['min' => round($standard - $step, 2), 'max' => round($standard + $step, 2)];
    }

    /**
     * Required concentration to start the exposure period, by time since injection.
     * Boundaries take the stricter standard. Null before 15 minutes (no start point possible yet).
     */
    public static function startPointStandard(float $initialDose, float $minutesSinceInjection, bool $eightyClass = false): ?float
    {
        if ($initialDose <= 0) {
            throw new InvalidArgumentException('Initial dose must be positive.');
        }
        if ($minutesSinceInjection < 15) {
            return null;
        }
        if ($eightyClass) {
            $percent = 80.0;
        } elseif ($minutesSinceInjection <= 30) {
            $percent = 85.0;
        } elseif ($minutesSinceInjection <= 60) {
            $percent = 75.0;
        } else {
            $percent = 70.0;
        }
        return round($initialDose * $percent / 100, 2);
    }
}
