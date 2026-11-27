<?php
declare(strict_types=1);

/** Records are kept at least five years (AFAS/BAF). Nothing is ever purged automatically: this only says whether an admin may. */
final class ApRetention
{
    public const MIN_YEARS = 5;

    public static function canPurge(DateTimeImmutable $closedAt, DateTimeImmutable $now, int $years = self::MIN_YEARS): bool
    {
        $years = max(self::MIN_YEARS, $years);
        return $now >= $closedAt->modify("+{$years} years");
    }
}
