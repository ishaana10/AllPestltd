<?php
declare(strict_types=1);

/** Methyl bromide dose arithmetic (methodology v3.0 sections 8.1, 8.2, 8.6, 10.3). Pure functions. */
final class ApDose
{
    private static function positive(float $v, string $what): void
    {
        if (!is_finite($v) || $v <= 0) {
            throw new InvalidArgumentException($what . ' must be a positive number.');
        }
    }

    private static function fraction(float $f): void
    {
        if (!is_finite($f) || $f <= 0 || $f > 1) {
            throw new InvalidArgumentException('Methyl bromide fraction must be above 0 and at most 1.');
        }
    }

    public static function volume(float $heightM, float $depthM, float $lengthM): float
    {
        self::positive($heightM, 'Height');
        self::positive($depthM, 'Depth');
        self::positive($lengthM, 'Length');
        return $heightM * $depthM * $lengthM;
    }

    /** Add 8 g/m3 for each 5 C, or part thereof, the forecast minimum is below the reference temperature. */
    public static function compensatedDoseRate(float $doseRate, float $forecastMinC, float $referenceC, bool $allowed): float
    {
        self::positive($doseRate, 'Dose rate');
        if (!$allowed || $forecastMinC >= $referenceC) {
            return $doseRate;
        }
        $steps = (int)ceil(($referenceC - $forecastMinC) / 5 - 1e-9);
        return $doseRate + 8.0 * $steps;
    }

    /** Dose (g) = volume x dose rate, divided by the methyl bromide fraction of the supplied gas (0.98 with 2% chloropicrin). */
    public static function doseGrams(float $volumeM3, float $doseRateUsed, float $mbFraction = 1.0): float
    {
        self::positive($volumeM3, 'Volume');
        self::positive($doseRateUsed, 'Dose rate');
        self::fraction($mbFraction);
        return $volumeM3 * $doseRateUsed / $mbFraction;
    }

    public static function roundUpToIncrement(float $grams, float $increment): float
    {
        self::positive($grams, 'Dose');
        self::positive($increment, 'Increment');
        return ceil($grams / $increment - 1e-9) * $increment;
    }

    public static function topUpGrams(float $volumeM3, float $maxTopUp, float $lowestReading, float $mbFraction = 1.0): float
    {
        self::positive($volumeM3, 'Volume');
        self::fraction($mbFraction);
        if ($lowestReading < 0 || $maxTopUp <= $lowestReading) {
            throw new InvalidArgumentException('Lowest reading must be below the maximum top-up concentration.');
        }
        return $volumeM3 * ($maxTopUp - $lowestReading) / $mbFraction;
    }

    public static function additionalDoseBeforeStart(float $volumeM3, float $initialDoseRate, float $lowestReading, float $mbFraction = 1.0): float
    {
        self::positive($volumeM3, 'Volume');
        self::fraction($mbFraction);
        if ($lowestReading < 0 || $initialDoseRate <= $lowestReading) {
            throw new InvalidArgumentException('Lowest reading must be below the initial dose rate.');
        }
        return $volumeM3 * ($initialDoseRate - $lowestReading) / $mbFraction;
    }
}
