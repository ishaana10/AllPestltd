<?php
declare(strict_types=1);

/** Per-container treatment results and the end-of-exposure top-up rule (methodology v3.0 sections 9.2, 10.2). Pure functions. */
final class ApResult
{
    private const EPS = 1e-9;

    /**
     * @param array<int,array{id:string,meetsStandard:bool}> $containers meetsStandard is judged after any top-up
     * @return array{overall:string,containers:array<string,string>} overall is pass, partial or fail
     */
    public static function evaluate(array $containers, bool $topUpStillPossible, bool $targetInsideContainers): array
    {
        if (!$containers) {
            throw new InvalidArgumentException('A treatment needs at least one container.');
        }
        $failing = [];
        foreach ($containers as $c) {
            if (!$c['meetsStandard']) {
                $failing[] = $c['id'];
            }
        }
        $per = [];
        if (!$failing) {
            foreach ($containers as $c) {
                $per[$c['id']] = 'pass';
            }
            return ['overall' => 'pass', 'containers' => $per];
        }
        // Section 9.2: a single container in a sheeted stack may fail alone when no compliant top-up is possible.
        if (count($failing) === 1 && !$topUpStillPossible && $targetInsideContainers && count($containers) > 1) {
            foreach ($containers as $c) {
                $per[$c['id']] = $c['id'] === $failing[0] ? 'fail' : 'pass';
            }
            return ['overall' => 'partial', 'containers' => $per];
        }
        foreach ($containers as $c) {
            $per[$c['id']] = 'fail';
        }
        return ['overall' => 'fail', 'containers' => $per];
    }

    /**
     * One top-up is permitted at the end of the exposure period, if the lowest reading is below the standard but not
     * below the minimum to allow top-up, the schedule permits it, and none was done before. The fumigation is then
     * extended by the schedule's time, or 4 hours when it specifies none.
     *
     * @return array{allowed:bool,extensionHours:float}
     */
    public static function endOfExposureTopUp(
        bool $schedulePermits,
        bool $alreadyDone,
        float $lowest,
        float $standard,
        float $minTopUp,
        ?float $scheduleExtensionHours = null
    ): array {
        if ($scheduleExtensionHours !== null && $scheduleExtensionHours <= 0) {
            throw new InvalidArgumentException('Schedule extension must be positive.');
        }
        $allowed = $schedulePermits
            && !$alreadyDone
            && $lowest < $standard - self::EPS
            && $lowest >= $minTopUp - self::EPS;
        return ['allowed' => $allowed, 'extensionHours' => $allowed ? ($scheduleExtensionHours ?? 4.0) : 0.0];
    }
}
