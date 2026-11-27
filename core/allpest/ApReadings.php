<?php
declare(strict_types=1);

/** Concentration reading checks (methodology v3.0 sections 5.3, 5.4, 8.5, 9.1, 11.1). Pure functions. */
final class ApReadings
{
    private const EPS = 1e-9;

    /** A reading is recorded exactly as the instrument shows it: a plain non-negative decimal, nothing else. */
    public static function parseReading(string $raw): float
    {
        $s = trim($raw);
        if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
            throw new InvalidArgumentException("'" . $raw . "' is not a valid reading. Enter the number exactly as the instrument shows it, for example 4.3.");
        }
        return (float)$s;
    }

    public static function equilibriumPercent(array $values): float
    {
        if (!$values) {
            throw new InvalidArgumentException('At least one reading is needed.');
        }
        $hi = max($values);
        $lo = min($values);
        if ($lo <= 0) {
            throw new InvalidArgumentException('Equilibrium needs readings above zero.');
        }
        return ($hi - $lo) / $lo * 100;
    }

    public static function isEquilibrium(array $values): bool
    {
        return self::equilibriumPercent($values) <= 15.0 + self::EPS;
    }

    /** @param array<string,float> $readingsByLocation */
    public static function evaluateSet(array $readingsByLocation, float $standard, float $minTopUp): array
    {
        if (!$readingsByLocation) {
            throw new InvalidArgumentException('At least one reading is needed.');
        }
        $below = [];
        foreach ($readingsByLocation as $loc => $v) {
            if ($v < $standard - self::EPS) {
                $below[] = $loc;
            }
        }
        $lowest = min($readingsByLocation);
        return [
            'allMeet' => $below === [],
            'belowLocations' => $below,
            'lowest' => (float)$lowest,
            'topUpPossible' => $lowest >= $minTopUp - self::EPS,
        ];
    }

    /** Minimum gas monitoring locations for one enclosure (or one sea container when $inSheetedStack). */
    public static function requiredLocations(string $type, float $volumeM3, int $packagingTypes = 1, bool $inSheetedStack = false): int
    {
        if ($type !== 'general' && $type !== 'perishable') {
            throw new InvalidArgumentException('Type must be general or perishable.');
        }
        if (!is_finite($volumeM3) || $volumeM3 <= 0) {
            throw new InvalidArgumentException('Volume must be positive.');
        }
        if ($packagingTypes < 1) {
            throw new InvalidArgumentException('Packaging types must be at least 1.');
        }
        if ($type === 'general') {
            return ($inSheetedStack || $volumeM3 >= 30) ? 3 : 1;
        }
        $base = $volumeM3 >= 5 ? 3 : 1;
        return max($base, $packagingTypes);
    }

    /** Hours (from the start of exposure) at which a reading set is due: start, every 24 h, and the end. */
    public static function readingsDue(float $exposureHours): array
    {
        if (!is_finite($exposureHours) || $exposureHours <= 0) {
            throw new InvalidArgumentException('Exposure hours must be positive.');
        }
        $due = [0.0];
        for ($t = 24.0; $t < $exposureHours - self::EPS; $t += 24.0) {
            $due[] = $t;
        }
        $due[] = (float)$exposureHours;
        return $due;
    }

    public static function tlvOk(float $ppm, float $limit = 5.0): bool
    {
        if ($ppm < 0) {
            throw new InvalidArgumentException('A TLV reading cannot be negative.');
        }
        return $ppm <= $limit + self::EPS;
    }
}
