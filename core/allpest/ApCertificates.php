<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApSettings.php';
require_once __DIR__ . '/ApUploads.php';
require_once __DIR__ . '/ApTemperature.php';
require_once __DIR__ . '/ApEquipment.php';
require_once __DIR__ . '/ApTreatments.php';
require_once __DIR__ . '/ApCertificateRenderer.php';

/**
 * One certificate per passing container, issued once both signatures exist (and, when the install asks for it, an admin has verified).
 * Certificates are generated at send time as PDF attachments and never stored; the retained record is the data, signatures and the
 * SHA-256 of the canonical certificate content.
 */
final class ApCertificates
{
    private const DECLARATION = 'I declare that the consignment described above was fumigated with methyl bromide in accordance with the Australian Methyl bromide fumigation methodology, and that the treatment met the required standard.';

    /** @var callable|null fn(string $to, string $subject, string $htmlBody, array $attachments): void  (throws on failure) */
    private static $mailer = null;

    public static function setMailer(?callable $mailer): void { self::$mailer = $mailer; }

    /** @return array{ok:bool,missing:string[]} */
    public static function releasable(string $containerId, bool $adminVerifyRequired): array
    {
        $db = ApDb::get();
        $tc = $db->fetchOne('SELECT * FROM ap_treatment_containers WHERE id = ?', [$containerId]);
        if (!$tc) { throw new InvalidArgumentException('Unknown container.'); }
        $t = $db->fetchOne('SELECT status, version FROM ap_treatments WHERE id = ?', [$tc['treatment_id']]);
        $missing = [];
        if (!$t || $t['status'] !== 'closed' || $tc['result'] !== 'pass') { $missing[] = 'pass'; }
        $roles = array_column($db->fetchAll('SELECT role FROM ap_signatures WHERE treatment_id = ? AND version = ?', [$tc['treatment_id'], (int)($t['version'] ?? 1)]), 'role');
        if (!in_array('fumigator', $roles, true)) { $missing[] = 'fumigator_signature'; }
        if (!in_array('officer', $roles, true)) { $missing[] = 'officer_signature'; }
        if ($adminVerifyRequired && empty($tc['verified_at'])) { $missing[] = 'admin_verification'; }
        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /** Optional second layer: an admin confirms a passing container's certificate may go out. */
    public static function verify(string $containerId, string $adminId): void
    {
        $db = ApDb::get();
        $tc = $db->fetchOne('SELECT treatment_id, result FROM ap_treatment_containers WHERE id = ?', [$containerId]);
        if (!$tc) { throw new InvalidArgumentException('Unknown container.'); }
        if ($tc['result'] !== 'pass') { throw new InvalidArgumentException('Only a container that passed can be verified for a certificate.'); }
        $db->update('ap_treatment_containers', ['verified_by' => $adminId, 'verified_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$containerId]);
        ApTreatments::audit('certificate.verified', (string)$tc['treatment_id'], ['container_id' => $containerId, 'admin' => $adminId]);
    }

    /**
     * Issue a certificate for every releasable container that has none, and email the customer once.
     * @return array{issued:string[],skipped:array<string,string[]>,existing:string[]} numbers issued now; containers not yet releasable (with what is missing); containers already certified
     */
    public static function issueEligible(string $treatmentId, DateTimeImmutable $now): array
    {
        $db = ApDb::get();
        $adminRequired = ApSettings::get('certificate_admin_verify', '0') === '1';
        $out = ['issued' => [], 'skipped' => [], 'existing' => []];
        $new = [];
        foreach ($db->fetchAll('SELECT tc.id FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no, tc.id', [$treatmentId]) as $tc) {
            $cid = (string)$tc['id'];
            if ($db->fetchOne('SELECT id FROM ap_certificates WHERE container_id = ?', [$cid])) { $out['existing'][] = $cid; continue; }
            $rel = self::releasable($cid, $adminRequired);
            if (!$rel['ok']) { $out['skipped'][$cid] = $rel['missing']; continue; }
            $cert = self::issueOne($treatmentId, $cid, $now);
            if ($cert === null) { $out['existing'][] = $cid; continue; } // another request issued it first
            $out['issued'][] = (string)$cert['number'];
            $new[] = $cert;
        }
        if ($new) { self::deliver($new); }
        return $out;
    }

    /** Retry certificate emails that failed. @return int certificates sent */
    public static function sendPending(): int
    {
        $byTreatment = [];
        foreach (ApDb::get()->fetchAll('SELECT * FROM ap_certificates WHERE email_pending = 1 ORDER BY number') as $c) { $byTreatment[$c['treatment_id']][] = $c; }
        $sent = 0;
        foreach ($byTreatment as $certs) { if (self::deliver($certs)) { $sent += count($certs); } }
        return $sent;
    }

    /**
     * The canonical content of a container's certificate. Numbers are fixed-decimal strings and times are Fiji local time, so the
     * same data always produces the same hash on any database driver. Keys starting with '_' are render-only and not hashed.
     * @return array<string,mixed>
     */
    public static function data(string $containerId, ?string $number = null, ?DateTimeImmutable $issuedAt = null): array
    {
        $db = ApDb::get();
        $tc = $db->fetchOne('SELECT * FROM ap_treatment_containers WHERE id = ?', [$containerId]);
        if (!$tc) { throw new InvalidArgumentException('Unknown container.'); }
        if ($number === null || $issuedAt === null) {
            $cert = $db->fetchOne('SELECT number, issued_at FROM ap_certificates WHERE container_id = ?', [$containerId]);
            if (!$cert) { throw new InvalidArgumentException('No certificate has been issued for this container.'); }
            $number = $number ?? (string)$cert['number'];
            $issuedAt = $issuedAt ?? new DateTimeImmutable((string)$cert['issued_at'], new DateTimeZone('UTC'));
        }
        $t = $db->fetchOne('SELECT * FROM ap_treatments WHERE id = ?', [$tc['treatment_id']]);
        $req = $db->fetchOne('SELECT * FROM ap_requests WHERE id = ?', [$t['request_id']]);
        $con = $db->fetchOne('SELECT * FROM ap_consignments WHERE id = ?', [$tc['consignment_id']]);
        $sched = $db->fetchOne('SELECT * FROM ap_schedules WHERE id = ?', [$t['schedule_id']]);
        $hours = ($t['start_point_at'] && $t['ventilation_at'])
            ? (strtotime((string)$t['ventilation_at'] . ' UTC') - strtotime((string)$t['start_point_at'] . ' UTC')) / 3600 : null;

        $equipment = [];
        foreach ((array)json_decode((string)$t['equipment_ids'], true) as $eid) {
            $e = $db->fetchOne('SELECT name, serial_no FROM ap_equipment WHERE id = ?', [(string)$eid]);
            if ($e) { $equipment[] = ['name' => (string)$e['name'], 'serial_last4' => substr((string)$e['serial_no'], -4)]; }
        }
        $signatures = [];
        $assets = [];
        foreach ($db->fetchAll('SELECT * FROM ap_signatures WHERE treatment_id = ? AND version = ?', [$t['id'], (int)$t['version']]) as $s) {
            $img = $s['image_file_id'] ? $db->fetchOne('SELECT sha256 FROM ap_files WHERE id = ?', [$s['image_file_id']]) : null;
            $signatures[$s['role']] = ['name' => (string)$s['signer_name'], 'designation' => (string)$s['signer_designation'], 'signed_at' => self::fiji((string)$s['signed_at']), 'image_sha256' => $img['sha256'] ?? null];
            if ($img) { $assets[$s['role'] . '_png'] = ApUploads::path((string)$s['image_file_id']); }
        }
        return [
            'number' => $number,
            'issued_at' => self::fiji($issuedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')),
            'provider' => ['name' => (string)ApSettings::get('company_name', 'All Pest Hygiene Solutions'), 'address' => (string)ApSettings::get('company_address', ''), 'provider_id' => (string)ApSettings::get('treatment_provider_id', '')],
            'request_reference' => (string)$req['reference'],
            'client' => ['name' => (string)$req['customer_name'], 'address' => (string)$req['customer_address']],
            'consignment' => ['container_no' => (string)$con['container_no'], 'seal_no' => (string)$con['seal_no'], 'commodity' => (string)$con['commodity'], 'origin' => (string)$con['origin'],
                'destination' => (string)$con['destination'], 'quantity' => (string)$con['quantity']],
            'target' => (string)ApSettings::get('certificate_target', 'Quarantine pests, as per the schedule'),
            'enclosure_type' => (string)$t['enclosure_type'], 'fumigation_type' => (string)$t['fumigation_type'], 'place' => (string)$req['site_address'],
            'schedule' => ['name' => (string)$sched['name'], 'dose_rate' => self::dec($sched['dose_rate']), 'exposure_hours' => self::dec($sched['exposure_hours']), 'min_temp_c' => self::dec($sched['min_temp_c'], 1)],
            'applied' => ['dose_rate_used' => self::dec($t['dose_rate_used']), 'applied_g' => self::dec($t['applied_g']), 'volume_m3' => self::dec($t['volume_m3']),
                'exposure_hours' => $hours === null ? '' : self::dec($hours), 'forecast_min_c' => self::dec($t['forecast_min_c'], 1),
                'topup_g' => $t['topup_at'] ? self::dec($t['topup_g']) : ''],
            'times' => ['start_point_at' => self::fiji((string)$t['start_point_at']), 'ventilation_at' => self::fiji((string)$t['ventilation_at'])],
            'tlv_ppm' => self::dec($tc['tlv_ppm']), 'tlv_at' => self::fiji((string)$tc['tlv_at']),
            'equipment' => $equipment, 'signatures' => $signatures, 'rof_version' => (int)$t['version'],
            'declaration' => (string)ApSettings::get('certificate_declaration', self::DECLARATION),
            '_assets' => $assets,
        ];
    }

    /** SHA-256 of the canonical certificate content rebuilt from the database (equals the stored hash while the data is unchanged). */
    public static function recomputeHash(string $containerId): string
    {
        return self::hash(self::data($containerId));
    }

    public static function hash(array $data): string
    {
        $canon = function ($v) use (&$canon) {
            if (!is_array($v)) { return $v; }
            $out = [];
            foreach ($v as $k => $x) { if (is_string($k) && $k !== '' && $k[0] === '_') { continue; } $out[$k] = $canon($x); }
            if (array_keys($out) !== range(0, count($out) - 1)) { ksort($out); }
            return $out;
        };
        return hash('sha256', (string)json_encode($canon($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ---- internals -----------------------------------------------------------------------------------------------

    /** @return array<string,mixed>|null the new certificate row, or null when the container already has one */
    private static function issueOne(string $treatmentId, string $containerId, DateTimeImmutable $now): ?array
    {
        $db = ApDb::get();
        $year = $now->setTimezone(new DateTimeZone(ApTemperature::TZ))->format('Y');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $db->beginTransaction();
            try {
                $last = $db->fetchOne('SELECT number FROM ap_certificate_numbers WHERE number LIKE ? ORDER BY number DESC LIMIT 1', ["AP-$year-%"]);
                $number = sprintf('AP-%s-%05d', $year, $last ? (int)substr((string)$last['number'], -5) + 1 : 1);
                $db->insert('ap_certificate_numbers', ['id' => ApId::uuid(), 'number' => $number, 'container_id' => $containerId, 'allocated_at' => gmdate('Y-m-d H:i:s')]);
                $tc = $db->fetchOne('SELECT verified_by, verified_at FROM ap_treatment_containers WHERE id = ?', [$containerId]);
                $id = ApId::uuid();
                $row = ['id' => $id, 'treatment_id' => $treatmentId, 'container_id' => $containerId, 'number' => $number, 'content_sha256' => self::hash(self::data($containerId, $number, $now)),
                    'issued_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'email_pending' => 1, 'verified_by' => $tc['verified_by'] ?? null,
                    'verified_at' => $tc['verified_at'] ?? null, 'created_at' => gmdate('Y-m-d H:i:s')];
                $db->insert('ap_certificates', $row);
                $db->commit();
                ApTreatments::audit('certificate.issued', $treatmentId, ['container_id' => $containerId, 'number' => $number, 'sha256' => $row['content_sha256']]);
                return $row;
            } catch (Throwable $e) {
                $db->rollback();
                if ($db->fetchOne('SELECT id FROM ap_certificates WHERE container_id = ?', [$containerId])) { return null; }
                if ($attempt === 5) { throw $e; }
            }
        }
        return null;
    }

    /** One email to the customer with a PDF per certificate. Marks them sent, or leaves them pending for a retry. */
    private static function deliver(array $certs): bool
    {
        $db = ApDb::get();
        $first = $certs[0];
        $req = $db->fetchOne('SELECT r.reference, r.customer_name, r.customer_email FROM ap_treatments t JOIN ap_requests r ON r.id = t.request_id WHERE t.id = ?', [$first['treatment_id']]);
        try {
            $attachments = [];
            $numbers = [];
            foreach ($certs as $c) {
                if (self::recomputeHash((string)$c['container_id']) !== (string)$c['content_sha256']) {
                    ApTreatments::audit('certificate.integrity_mismatch', (string)$c['treatment_id'], ['container_id' => $c['container_id'], 'number' => $c['number']]);
                    throw new RuntimeException('Certificate ' . $c['number'] . ' no longer matches the content issued: not sent.');
                }
                $attachments[] = ['filename' => 'Certificate-' . $c['number'] . '.pdf', 'mimetype' => 'application/pdf', 'data' => ApCertificateRenderer::render(self::data((string)$c['container_id']))];
                $numbers[] = (string)$c['number'];
            }
            $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subject = 'Fumigation certificate' . (count($numbers) > 1 ? 's' : '') . ' ' . implode(', ', $numbers) . ' (' . $req['reference'] . ')';
            $body = '<p>Dear ' . $e((string)$req['customer_name']) . ',</p><p>Please find attached the fumigation certificate' . (count($numbers) > 1 ? 's' : '') . ' for request <b>'
                . $e((string)$req['reference']) . '</b>: ' . $e(implode(', ', $numbers)) . '.</p>';
            self::send((string)$req['customer_email'], $subject, $body, $attachments);
        } catch (Throwable $err) {
            error_log('[allpest certificate email] ' . $err->getMessage()); // certificates stay issued; the retry picks them up
            return false;
        }
        foreach ($certs as $c) {
            $db->update('ap_certificates', ['emailed_at' => gmdate('Y-m-d H:i:s'), 'email_pending' => 0], 'id = ?', [$c['id']]);
        }
        return true;
    }

    private static function send(string $to, string $subject, string $body, array $attachments): void
    {
        if (self::$mailer !== null) { (self::$mailer)($to, $subject, $body, $attachments); return; }
        if (!class_exists('EmailService')) { throw new RuntimeException('Email is not available.'); }
        $r = (new EmailService())->send($to, $subject, $body, ['attachments' => $attachments, 'source' => 'allpest']);
        if (empty($r['success'])) { throw new RuntimeException((string)($r['message'] ?? 'send failed')); }
    }

    private static function dec($v, int $places = 2): string
    {
        return $v === null || $v === '' ? '' : number_format((float)$v, $places, '.', '');
    }

    private static function fiji(string $utc): string
    {
        if ($utc === '') { return ''; }
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(ApTemperature::TZ))->format('Y-m-d H:i');
    }
}
