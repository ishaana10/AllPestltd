<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';

/** Fumigation technicians (with registrations) and the supervising Biosecurity officers (no login). */
final class ApTechnicians
{
    /** @param array<string,mixed> $data full_name, registration_no, registration_expiry (Y-m-d), accreditation_no, user_id, status */
    public static function create(array $data): string
    {
        $id = ApId::uuid();
        $allowed = ['user_id', 'full_name', 'registration_no', 'registration_expiry', 'accreditation_no', 'signature_file_id', 'status'];
        $row = array_intersect_key($data, array_flip($allowed));
        $row['id'] = $id;
        $row['created_at'] = gmdate('Y-m-d H:i:s');
        ApDb::get()->insert('ap_technicians', $row);
        return $id;
    }

    /** Attach an uploaded file (registration, certificate) to a technician. */
    public static function attachFile(string $technicianId, string $fileId, string $label): void
    {
        ApDb::get()->insert('ap_technician_files', [
            'id' => ApId::uuid(), 'technician_id' => $technicianId, 'file_id' => $fileId,
            'label' => $label, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Active and holding a registration that has not expired (valid through its expiry day, Fiji or UTC alike). */
    public static function canSign(string $technicianId, DateTimeImmutable $now): bool
    {
        $t = ApDb::get()->fetchOne('SELECT status, registration_expiry FROM ap_technicians WHERE id = ?', [$technicianId]);
        if (!$t || $t['status'] !== 'active' || empty($t['registration_expiry'])) {
            return false;
        }
        return (string)$t['registration_expiry'] >= $now->format('Y-m-d');
    }

    /** Active technicians whose registration expires from today through today + $days (inclusive). @return array<int,array<string,mixed>> */
    public static function expiringWithin(int $days, DateTimeImmutable $now): array
    {
        $from = $now->format('Y-m-d');
        $to = $now->modify('+' . $days . ' days')->format('Y-m-d');
        return ApDb::get()->fetchAll(
            "SELECT * FROM ap_technicians WHERE status = 'active' AND registration_expiry IS NOT NULL AND registration_expiry >= ? AND registration_expiry <= ? ORDER BY registration_expiry",
            [$from, $to]
        );
    }

    public static function addOfficer(string $name, string $designation): string
    {
        $id = ApId::uuid();
        ApDb::get()->insert('ap_officers', ['id' => $id, 'full_name' => $name, 'designation' => $designation, 'created_at' => gmdate('Y-m-d H:i:s')]);
        return $id;
    }

    /** @return array<int,array<string,mixed>> active officers */
    public static function officers(): array
    {
        return ApDb::get()->fetchAll("SELECT * FROM ap_officers WHERE status = 'active' ORDER BY full_name");
    }
}
