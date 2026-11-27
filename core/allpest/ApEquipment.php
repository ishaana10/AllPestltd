<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';

/** Fumigation equipment inventory: what a technician may select, and the audit/discard/replace/repair log. */
final class ApEquipment
{
    private const STATUS_AFTER = ['discard' => 'discarded', 'replace' => 'replaced', 'repair' => 'in_repair'];

    /** In-service items whose audit/calibration is not overdue and which have not expired. @return array<int,array<string,mixed>> */
    public static function selectable(DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');
        $out = [];
        foreach (ApDb::get()->fetchAll("SELECT * FROM ap_equipment WHERE status = 'in_service' ORDER BY kind, name") as $e) {
            if (!empty($e['next_audit_due']) && (string)$e['next_audit_due'] < $today) { continue; }
            if (!empty($e['expiry_date']) && (string)$e['expiry_date'] < $today) { continue; }
            $out[] = $e;
        }
        return $out;
    }

    /**
     * Log an event and apply its status change. Rows are never deleted. An audit also returns an item from repair to service
     * and, when $nextAuditDue (Y-m-d) is given, moves the due date.
     */
    public static function logEvent(string $equipmentId, string $kind, string $note, string $userId, ?string $nextAuditDue = null): void
    {
        if (!in_array($kind, ['audit', 'discard', 'replace', 'repair'], true)) {
            throw new InvalidArgumentException("Unknown equipment event '$kind'");
        }
        $db = ApDb::get();
        $item = $db->fetchOne('SELECT status FROM ap_equipment WHERE id = ?', [$equipmentId]);
        if (!$item) {
            throw new InvalidArgumentException('Unknown equipment');
        }
        $db->insert('ap_equipment_log', [
            'id' => ApId::uuid(), 'equipment_id' => $equipmentId, 'kind' => $kind, 'note' => $note,
            'user_id' => $userId, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $set = ['updated_at' => gmdate('Y-m-d H:i:s')];
        if (isset(self::STATUS_AFTER[$kind])) {
            $set['status'] = self::STATUS_AFTER[$kind];
        } elseif ($item['status'] === 'in_repair') {
            $set['status'] = 'in_service';
        }
        if ($kind === 'audit' && $nextAuditDue !== null) {
            $set['next_audit_due'] = $nextAuditDue;
        }
        $db->update('ap_equipment', $set, 'id = ?', [$equipmentId]);
    }

    /** Last four characters of the serial number, as printed on the certificate. */
    public static function serialLast4(string $equipmentId): string
    {
        $row = ApDb::get()->fetchOne('SELECT serial_no FROM ap_equipment WHERE id = ?', [$equipmentId]);
        return $row ? substr((string)$row['serial_no'], -4) : '';
    }
}
