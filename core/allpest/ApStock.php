<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';

/**
 * Methyl bromide stock ledger. Movements are append-only signed grams (receive +, consume -, adjust +/-);
 * the balance is their sum. Gas used is never refused: an overdraw is recorded and flagged for follow-up.
 */
final class ApStock
{
    public static function createLot(string $lotRef, string $description, float $mbFraction): string
    {
        if (trim($lotRef) === '') { throw new InvalidArgumentException('Lot reference is required'); }
        if ($mbFraction <= 0 || $mbFraction > 1) { throw new InvalidArgumentException('Methyl bromide fraction must be in (0, 1]'); }
        $id = ApId::uuid();
        ApDb::get()->insert('ap_stock_lots', ['id' => $id, 'lot_ref' => $lotRef, 'description' => $description, 'mb_fraction' => $mbFraction, 'created_at' => gmdate('Y-m-d H:i:s')]);
        return $id;
    }

    public static function receive(string $lotId, float $grams, string $userId): void
    {
        self::requirePositive($grams);
        self::record($lotId, 'receive', $grams, null, null, false, $userId);
    }

    public static function consume(string $lotId, string $treatmentId, float $grams, string $userId): void
    {
        self::requirePositive($grams);
        $discrepancy = self::balance($lotId) < $grams;
        self::record($lotId, 'consume', -$grams, $treatmentId, null, $discrepancy, $userId);
    }

    /** Signed correction (leak, count difference); the reason is mandatory. */
    public static function adjust(string $lotId, float $grams, string $reason, string $userId): void
    {
        if (trim($reason) === '') { throw new InvalidArgumentException('A reason is required for a stock adjustment'); }
        if ($grams == 0.0) { throw new InvalidArgumentException('Adjustment must not be zero'); }
        self::record($lotId, 'adjust', $grams, null, $reason, false, $userId);
    }

    public static function balance(string $lotId): float
    {
        $r = ApDb::get()->fetchOne('SELECT COALESCE(SUM(grams), 0) AS b FROM ap_stock_movements WHERE lot_id = ?', [$lotId]);
        return (float)($r['b'] ?? 0);
    }

    /** Lots whose balance is below the threshold. @return array<int,array{lot_id:string,lot_ref:string,balance:float}> */
    public static function lowStock(float $thresholdGrams): array
    {
        $out = [];
        foreach (ApDb::get()->fetchAll('SELECT id, lot_ref FROM ap_stock_lots ORDER BY lot_ref') as $lot) {
            $b = self::balance((string)$lot['id']);
            if ($b < $thresholdGrams) {
                $out[] = ['lot_id' => $lot['id'], 'lot_ref' => $lot['lot_ref'], 'balance' => $b];
            }
        }
        return $out;
    }

    /** Gas consumed from $from to $to inclusive (UTC). @return array{total_g:float,lots:array<int,array<string,mixed>>} */
    public static function usageReport(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = ApDb::get()->fetchAll(
            "SELECT m.lot_id, l.lot_ref, -SUM(m.grams) AS grams FROM ap_stock_movements m LEFT JOIN ap_stock_lots l ON l.id = m.lot_id
             WHERE m.kind = 'consume' AND m.created_at >= ? AND m.created_at <= ? GROUP BY m.lot_id, l.lot_ref ORDER BY l.lot_ref",
            [$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]
        );
        $total = 0.0;
        foreach ($rows as &$r) { $r['grams'] = (float)$r['grams']; $total += $r['grams']; }
        return ['total_g' => $total, 'lots' => $rows];
    }

    private static function requirePositive(float $grams): void
    {
        if ($grams <= 0) { throw new InvalidArgumentException('Grams must be greater than 0'); }
    }

    private static function record(string $lotId, string $kind, float $signedGrams, ?string $treatmentId, ?string $reason, bool $discrepancy, string $userId): void
    {
        ApDb::get()->insert('ap_stock_movements', [
            'id' => ApId::uuid(), 'lot_id' => $lotId, 'kind' => $kind, 'grams' => $signedGrams, 'treatment_id' => $treatmentId,
            'reason' => $reason, 'discrepancy' => $discrepancy ? 1 : 0, 'user_id' => $userId, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
