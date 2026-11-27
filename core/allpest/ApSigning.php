<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApUploads.php';
require_once __DIR__ . '/ApTechnicians.php';
require_once __DIR__ . '/ApTreatments.php';

/** The signer is not allowed to sign (wrong credentials, wrong person, expired registration, bad signature image, wrong state). */
class ApSigningRefused extends RuntimeException
{
}

/**
 * E-signatures on a closed ROF and corrections to a signed one.
 * The fumigator-in-charge signs with their account password (and MFA code when they use one); the supervising Biosecurity officer,
 * who has no account, draws their signature on the technician's tablet. The first signature locks the ROF; a correction creates a
 * new version, keeps the old one, and requires both signatures again.
 */
final class ApSigning
{
    private const MIN_SIGNATURE_PIXELS = 30;
    private const MAX_PNG_BYTES = 1048576;
    private const MAX_PNG_SIDE = 3000;
    private const CORRECTABLE = ['enclosure_type', 'fumigation_type', 'height_m', 'depth_m', 'length_m', 'volume_m3', 'mb_fraction', 'dose_rate_used', 'dose_g',
        'applied_g', 'equipment_ids', 'officer_id', 'scheduled_start', 'site_lat', 'site_lng'];

    /** @var callable fn(string $userId, string $password, ?string $mfaCode): bool */
    private $verifyCredential;

    public function __construct(callable $verifyCredential)
    {
        $this->verifyCredential = $verifyCredential;
    }

    /** @return bool true when the signature was recorded, false when this version was already signed by the fumigator (double click) */
    public function fumigatorSign(string $treatmentId, string $userId, string $password, ?string $mfaCode, DateTimeImmutable $now): bool
    {
        $t = $this->signable($treatmentId);
        $tech = ApDb::get()->fetchOne('SELECT * FROM ap_technicians WHERE id = ?', [(string)$t['technician_id']]);
        if (!$tech || (string)$tech['user_id'] === '' || (string)$tech['user_id'] !== $userId) {
            throw new ApSigningRefused('Only the fumigator-in-charge named on this ROF can sign it.');
        }
        if (!ApTechnicians::canSign((string)$tech['id'], $now->setTimezone(new DateTimeZone(ApTemperature::TZ)))) {
            throw new ApSigningRefused('Your registration is expired or your profile is not active, so you cannot sign.');
        }
        if (!($this->verifyCredential)($userId, $password, $mfaCode)) {
            throw new ApSigningRefused('Your password or verification code is not correct.');
        }
        if ($this->existing($treatmentId, 'fumigator', (int)$t['version'])) {
            return false;
        }
        $this->record($t, 'fumigator', (string)$tech['full_name'], 'Fumigator-in-charge', $userId, $tech['signature_file_id'] ?: null, $now);
        return true;
    }

    /**
     * The officer draws on the technician's tablet; $technicianUserId is the signed-in technician whose device captured it.
     * @return bool true when recorded, false when this version already has an officer signature (the first one stands)
     */
    public function officerSign(string $treatmentId, string $name, string $designation, string $pngDataUrl, string $technicianUserId, DateTimeImmutable $now): bool
    {
        $t = $this->signable($treatmentId);
        $tech = ApDb::get()->fetchOne('SELECT user_id FROM ap_technicians WHERE id = ?', [(string)$t['technician_id']]);
        if (!$tech || (string)$tech['user_id'] === '' || (string)$tech['user_id'] !== $technicianUserId) {
            throw new ApSigningRefused('The officer signature must be captured on the fumigator-in-charge\'s device.');
        }
        $name = trim($name);
        $designation = trim($designation);
        if ($name === '' || $designation === '') { throw new ApSigningRefused('Enter the officer\'s name and designation.'); }
        $png = $this->decodePng($pngDataUrl);
        if ($this->existing($treatmentId, 'officer', (int)$t['version'])) {
            return false;
        }
        $file = ApUploads::storePng($png, 'signature', $technicianUserId, self::device());
        $this->record($t, 'officer', mb_substr($name, 0, 150), mb_substr($designation, 0, 150), $technicianUserId, $file['file_id'], $now);
        return true;
    }

    public function isLocked(string $treatmentId): bool
    {
        $t = ApDb::get()->fetchOne('SELECT locked_at FROM ap_treatments WHERE id = ?', [$treatmentId]);
        return $t && !empty($t['locked_at']);
    }

    /** Signatures that count for the treatment's current version, keyed by role. @return array<string,array<string,mixed>> */
    public function signatures(string $treatmentId): array
    {
        $t = $this->load($treatmentId);
        $out = [];
        foreach (ApDb::get()->fetchAll('SELECT * FROM ap_signatures WHERE treatment_id = ? AND version = ? ORDER BY signed_at', [$treatmentId, (int)$t['version']]) as $s) {
            $out[(string)$s['role']] = $s;
        }
        return $out;
    }

    public function isFullySigned(string $treatmentId): bool
    {
        $s = $this->signatures($treatmentId);
        return isset($s['fumigator'], $s['officer']);
    }

    /**
     * Correct a signed ROF. Saves version N as it was, applies the changes as version N+1 and requires both signatures again.
     * @param array<string,mixed> $changes only treatment-level fields in the whitelist
     * @return int the new version number
     */
    public function correct(string $treatmentId, array $changes, string $reason, string $userId): int
    {
        $db = ApDb::get();
        $t = $this->load($treatmentId);
        if (empty($t['locked_at'])) { throw new ApTreatmentError('This ROF is not signed yet: edit it directly. Corrections apply to signed ROFs.'); }
        if (trim($reason) === '') { throw new InvalidArgumentException('A reason is required for a correction.'); }
        if (!$changes) { throw new InvalidArgumentException('Nothing to change.'); }
        foreach (array_keys($changes) as $k) {
            if (!in_array($k, self::CORRECTABLE, true)) { throw new InvalidArgumentException("The field '$k' cannot be corrected here."); }
        }
        $n = (int)$t['version'];
        $snapshot = [
            'treatment' => $t,
            'containers' => $db->fetchAll('SELECT * FROM ap_treatment_containers WHERE treatment_id = ? ORDER BY id', [$treatmentId]),
            'reading_sets' => array_map(fn ($rs) => $rs + ['readings' => $db->fetchAll('SELECT * FROM ap_readings WHERE reading_set_id = ? ORDER BY container_id, location', [$rs['id']])],
                $db->fetchAll('SELECT * FROM ap_reading_sets WHERE treatment_id = ? ORDER BY taken_at', [$treatmentId])),
            'signatures' => $db->fetchAll('SELECT * FROM ap_signatures WHERE treatment_id = ? AND version = ?', [$treatmentId, $n]),
        ];
        $db->beginTransaction();
        try {
            $db->insert('ap_treatment_versions', ['id' => ApId::uuid(), 'treatment_id' => $treatmentId, 'version' => $n, 'snapshot' => json_encode($snapshot),
                'reason' => trim($reason), 'user_id' => $userId, 'created_at' => gmdate('Y-m-d H:i:s')]);
            $db->update('ap_treatments', $changes + ['version' => $n + 1, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$treatmentId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        ApTreatments::audit('treatment.corrected', $treatmentId, ['from_version' => $n, 'to_version' => $n + 1, 'reason' => trim($reason), 'changes' => $changes]);
        return $n + 1;
    }

    /** A stored earlier version as it was (treatment, containers, reading sets with readings, signatures). @return array<string,mixed> */
    public function version(string $treatmentId, int $version): array
    {
        $r = ApDb::get()->fetchOne('SELECT snapshot FROM ap_treatment_versions WHERE treatment_id = ? AND version = ?', [$treatmentId, $version]);
        if (!$r) { throw new InvalidArgumentException('Unknown version.'); }
        return json_decode((string)$r['snapshot'], true) ?: [];
    }

    // ---- internals -----------------------------------------------------------------------------------------------

    private function load(string $id): array
    {
        $t = ApDb::get()->fetchOne('SELECT * FROM ap_treatments WHERE id = ?', [$id]);
        if (!$t) { throw new InvalidArgumentException('Unknown treatment.'); }
        return $t;
    }

    private function signable(string $treatmentId): array
    {
        $t = $this->load($treatmentId);
        if ($t['status'] !== 'closed') { throw new ApSigningRefused('The ROF can be signed once the treatment is closed.'); }
        return $t;
    }

    private function existing(string $treatmentId, string $role, int $version): bool
    {
        return (bool)ApDb::get()->fetchOne('SELECT id FROM ap_signatures WHERE treatment_id = ? AND role = ? AND version = ?', [$treatmentId, $role, $version]);
    }

    private function record(array $t, string $role, string $name, string $designation, string $userId, ?string $imageFileId, DateTimeImmutable $now): void
    {
        $db = ApDb::get();
        $at = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $db->insert('ap_signatures', ['id' => ApId::uuid(), 'treatment_id' => $t['id'], 'role' => $role, 'signer_name' => $name, 'signer_designation' => $designation,
                'user_id' => $userId, 'image_file_id' => $imageFileId, 'signed_at' => $at, 'device' => self::device(), 'version' => (int)$t['version']]);
            if (empty($t['locked_at'])) {
                $db->update('ap_treatments', ['locked_at' => $at, 'updated_at' => $at], 'id = ?', [$t['id']]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        ApTreatments::audit('treatment.signed.' . $role, (string)$t['id'], ['signer' => $name, 'designation' => $designation, 'version' => (int)$t['version'], 'signed_at' => $at]);
    }

    /** Decode a data URL, check it is a real drawing (enough non-transparent pixels), and return the PNG bytes. */
    private function decodePng(string $dataUrl): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) { throw new ApSigningRefused('The signature image is not valid.'); }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($bytes === false || strlen($bytes) > self::MAX_PNG_BYTES || strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) !== 0) { throw new ApSigningRefused('The signature image is not valid.'); }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] > self::MAX_PNG_SIDE || $info[1] > self::MAX_PNG_SIDE) { throw new ApSigningRefused('The signature image is not valid.'); }
        $im = @imagecreatefromstring($bytes);
        if ($im === false) { throw new ApSigningRefused('The signature image is not valid.'); }
        $w = imagesx($im);
        $h = imagesy($im);
        $drawn = 0;
        for ($y = 0; $y < $h && $drawn < self::MIN_SIGNATURE_PIXELS; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 127 && ++$drawn >= self::MIN_SIGNATURE_PIXELS) { break; }
            }
        }
        if ($drawn < self::MIN_SIGNATURE_PIXELS) { throw new ApSigningRefused('Please draw the signature in the box.'); }
        return $bytes;
    }

    private static function device(): string
    {
        return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255);
    }
}
