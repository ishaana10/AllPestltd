<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApSettings.php';
require_once __DIR__ . '/ApUploads.php';
require_once __DIR__ . '/ApTechnicians.php';

class ApIntakeRejected extends RuntimeException
{
}

/** An illegal request status jump, or an action that the request's current status does not allow. */
class ApRequestStateError extends RuntimeException
{
}

/** Customer fumigation requests. submitPublic() is the only write path for the no-login form; it never reads data back to the visitor. */
final class ApRequests
{
    public const MAX_CONSIGNMENTS = 50;
    public const MAX_FILES = 10;
    public const MAX_PER_IP_PER_HOUR = 5;

    /** @var callable|null fn(string $to, string $subject, string $htmlBody): void */
    private static $mailer = null;

    public static function setMailer(?callable $mailer): void
    {
        self::$mailer = $mailer;
    }

    /**
     * @param array<string,mixed> $input customer_*, site_address, requested_date, client_token, website (honeypot), consignments[]
     * @param array<int|string,array<string,mixed>> $files BOL uploads keyed by consignment index ($_FILES entries)
     * @return array{request_id:string,reference:string}
     */
    public static function submitPublic(array $input, array $files, string $ip): array
    {
        $db = ApDb::get();
        if (trim((string)($input['website'] ?? '')) !== '') {
            throw new ApIntakeRejected('Submission rejected.');
        }
        $token = trim((string)($input['client_token'] ?? ''));
        if ($token !== '') {
            $dup = $db->fetchOne('SELECT id, reference FROM ap_requests WHERE client_token = ?', [$token]);
            if ($dup) { return ['request_id' => (string)$dup['id'], 'reference' => (string)$dup['reference']]; }
        }

        $name = trim((string)($input['customer_name'] ?? ''));
        $email = trim((string)($input['customer_email'] ?? ''));
        $site = trim((string)($input['site_address'] ?? ''));
        if ($name === '') { throw new ApIntakeRejected('Please enter your name or company.'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new ApIntakeRejected('Please enter a valid email address.'); }
        if ($site === '') { throw new ApIntakeRejected('Please enter the site where the fumigation is to take place.'); }
        $cons = array_values(is_array($input['consignments'] ?? null) ? $input['consignments'] : []);
        if (count($cons) < 1) { throw new ApIntakeRejected('Add at least one container.'); }
        if (count($cons) > self::MAX_CONSIGNMENTS) { throw new ApIntakeRejected('At most ' . self::MAX_CONSIGNMENTS . ' containers per request.'); }
        if (count($files) > self::MAX_FILES) { throw new ApIntakeRejected('At most ' . self::MAX_FILES . ' files per request.'); }

        self::throttle($ip);

        $stored = [];
        $db->beginTransaction();
        try {
            $requestId = ApId::uuid();
            $reference = self::nextReference();
            $db->insert('ap_requests', [
                'id' => $requestId, 'reference' => $reference, 'job_type' => 'fumigation', 'status' => 'new',
                'customer_name' => mb_substr($name, 0, 200), 'customer_email' => $email,
                'customer_phone' => mb_substr(trim((string)($input['customer_phone'] ?? '')), 0, 50),
                'customer_tin' => mb_substr(trim((string)($input['customer_tin'] ?? '')), 0, 50),
                'customer_address' => trim((string)($input['customer_address'] ?? '')), 'site_address' => $site,
                'requested_date' => self::date($input['requested_date'] ?? null), 'client_token' => $token !== '' ? mb_substr($token, 0, 64) : null,
                'ip' => mb_substr($ip, 0, 45), 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            foreach ($cons as $i => $c) {
                $bol = null;
                if (isset($files[$i]) && is_array($files[$i]) && (int)($files[$i]['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                    try {
                        $f = ApUploads::store($files[$i], 'bol', $ip, 'public-form');
                    } catch (ApUploadRejected $e) {
                        throw new ApIntakeRejected('Bill of lading for container ' . ($i + 1) . ': ' . $e->getMessage());
                    }
                    $stored[] = $f['file_id'];
                    $bol = $f['file_id'];
                }
                $db->insert('ap_consignments', [
                    'id' => ApId::uuid(), 'request_id' => $requestId,
                    'container_no' => self::clip($c['container_no'] ?? '', 30), 'seal_no' => self::clip($c['seal_no'] ?? '', 40),
                    'commodity' => self::clip($c['commodity'] ?? '', 200), 'origin' => self::clip($c['origin'] ?? '', 100),
                    'destination' => self::clip($c['destination'] ?? '', 100), 'quantity' => self::clip($c['quantity'] ?? '', 80),
                    'bol_file_id' => $bol, 'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            $db->commit();
        } catch (Throwable $e) {
            foreach ($stored as $fileId) { self::discardFile($fileId); } // before rollback: the path lookup needs the ap_files row
            $db->rollback();
            throw $e;
        }

        ApAudit::request('request.received', $requestId, ['reference' => $reference, 'containers' => count($cons)]);
        self::notify($reference, $name, $email, count($cons), $site);
        return ['request_id' => $requestId, 'reference' => $reference];
    }

    private const NEXT = ['new' => 'confirmed', 'confirmed' => 'scheduled', 'scheduled' => 'in_progress', 'in_progress' => 'completed'];

    /**
     * Admin review: new -> confirmed. $plan may carry site_lat / site_lng, which are saved on the request.
     * @param array<string,mixed> $plan
     */
    public static function confirm(string $requestId, array $plan, string $adminId): void
    {
        self::importContainerLists($requestId);
        $set = [];
        foreach (['site_lat', 'site_lng'] as $k) {
            if (isset($plan[$k]) && is_numeric($plan[$k])) { $set[$k] = (float)$plan[$k]; }
        }
        self::advance($requestId, 'confirmed', $set + ['notes' => trim((string)($plan['notes'] ?? '')) ?: null]);
    }

    /**
     * Requests that arrive through the studio's public form carry their containers as text (one per line:
     * container no, seal no, commodity, origin, destination, quantity). Turn that into consignment rows, once.
     * @return int requests imported
     */
    public static function importContainerLists(?string $onlyRequestId = null): int
    {
        $db = ApDb::get();
        $sql = "SELECT id, container_list FROM ap_requests WHERE container_list IS NOT NULL AND TRIM(container_list) <> '' AND NOT EXISTS (SELECT 1 FROM ap_consignments c WHERE c.request_id = ap_requests.id)";
        $params = [];
        if ($onlyRequestId !== null) { $sql .= ' AND id = ?'; $params[] = $onlyRequestId; }
        $n = 0;
        foreach ($db->fetchAll($sql, $params) as $r) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$r['container_list'])), fn ($l) => $l !== ''));
            $lines = array_slice($lines, 0, self::MAX_CONSIGNMENTS);
            $db->beginTransaction();
            try {
                foreach ($lines as $line) {
                    $p = array_map('trim', preg_split('/[,\t;]/', $line));
                    $db->insert('ap_consignments', [
                        'id' => ApId::uuid(), 'request_id' => $r['id'], 'container_no' => self::clip($p[0] ?? '', 30), 'seal_no' => self::clip($p[1] ?? '', 40),
                        'commodity' => self::clip($p[2] ?? '', 200), 'origin' => self::clip($p[3] ?? '', 100), 'destination' => self::clip($p[4] ?? '', 100),
                        'quantity' => self::clip($p[5] ?? '', 80), 'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }
                $db->commit();
                $n++;
            } catch (Throwable $e) {
                $db->rollback();
                error_log('[allpest container import] ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** Move one step along new -> confirmed -> scheduled -> in_progress -> completed; anything else throws. */
    public static function advance(string $requestId, string $to, array $extra = []): void
    {
        $db = ApDb::get();
        $req = $db->fetchOne('SELECT status FROM ap_requests WHERE id = ?', [$requestId]);
        if (!$req) { throw new InvalidArgumentException('Unknown request'); }
        $from = (string)$req['status'];
        if ((self::NEXT[$from] ?? null) !== $to) {
            throw new ApRequestStateError("A request cannot go from '$from' to '$to'.");
        }
        $extra = array_filter($extra, fn ($v) => $v !== null);
        $db->update('ap_requests', $extra + ['status' => $to, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$requestId]);
        ApAudit::request('request.' . $to, $requestId, ['from' => $from, 'to' => $to]);
    }

    /**
     * Group consignments of a confirmed request into one treatment (one ROF). A request may have several treatments;
     * each container belongs to exactly one. $plan: scheduled_start (UTC), site_lat, site_lng, technician_id, officer_id,
     * schedule_id, rof_type (general|perishable), enclosure_type, fumigation_type (ambient|controlled).
     * @param string[] $containerIds consignment ids
     * @param array<string,mixed> $plan
     * @return string treatment id
     */
    public static function createTreatment(string $requestId, array $containerIds, array $plan): string
    {
        $db = ApDb::get();
        $req = $db->fetchOne('SELECT status, job_type FROM ap_requests WHERE id = ?', [$requestId]);
        if (!$req) { throw new InvalidArgumentException('Unknown request'); }
        if (!in_array($req['status'], ['confirmed', 'scheduled'], true)) {
            throw new ApRequestStateError("A request must be confirmed before it is scheduled (it is '{$req['status']}').");
        }
        if ($req['job_type'] !== 'fumigation') { throw new InvalidArgumentException('Treatment screens exist for fumigation requests only.'); }

        $ids = array_values(array_unique(array_map('strval', $containerIds)));
        if (!$ids) { throw new InvalidArgumentException('Choose at least one container.'); }
        $rof = (string)($plan['rof_type'] ?? '');
        if (!in_array($rof, ['general', 'perishable'], true)) { throw new InvalidArgumentException('ROF type must be general or perishable.'); }
        $fum = (string)($plan['fumigation_type'] ?? '');
        if (!in_array($fum, ['ambient', 'controlled'], true)) { throw new InvalidArgumentException('Fumigation type must be ambient or controlled.'); }
        try {
            $start = new DateTimeImmutable((string)($plan['scheduled_start'] ?? ''), new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            throw new InvalidArgumentException('A scheduled start time is required.');
        }
        $techId = (string)($plan['technician_id'] ?? '');
        if (!ApTechnicians::canSign($techId, $start->setTimezone(new DateTimeZone('Pacific/Fiji')))) {
            throw new InvalidArgumentException('The technician is not active or their registration will have expired by the treatment date.');
        }
        $officerId = (string)($plan['officer_id'] ?? '');
        if ($officerId === '' || !$db->fetchOne("SELECT id FROM ap_officers WHERE id = ? AND status = 'active'", [$officerId])) {
            throw new InvalidArgumentException('Choose a supervising Biosecurity officer.');
        }
        $sched = $db->fetchOne('SELECT treatment_type, active FROM ap_schedules WHERE id = ?', [(string)($plan['schedule_id'] ?? '')]);
        if (!$sched || (int)$sched['active'] !== 1) { throw new InvalidArgumentException('Choose an active treatment schedule.'); }
        if ($sched['treatment_type'] !== $rof) { throw new InvalidArgumentException('The schedule is for ' . $sched['treatment_type'] . ' treatments, not ' . $rof . '.'); }

        $db->beginTransaction();
        try {
            foreach ($ids as $cid) {
                $c = $db->fetchOne('SELECT request_id FROM ap_consignments WHERE id = ?', [$cid]);
                if (!$c || $c['request_id'] !== $requestId) { throw new InvalidArgumentException('A container does not belong to this request.'); }
                if ($db->fetchOne('SELECT id FROM ap_treatment_containers WHERE consignment_id = ?', [$cid])) {
                    throw new InvalidArgumentException('A container is already in another treatment.');
                }
            }
            $tid = ApId::uuid();
            $db->insert('ap_treatments', [
                'id' => $tid, 'request_id' => $requestId, 'rof_type' => $rof, 'status' => 'planned', 'schedule_id' => (string)$plan['schedule_id'],
                'technician_id' => $techId, 'officer_id' => $officerId, 'scheduled_start' => $start->format('Y-m-d H:i:s'),
                'site_lat' => isset($plan['site_lat']) && is_numeric($plan['site_lat']) ? (float)$plan['site_lat'] : null,
                'site_lng' => isset($plan['site_lng']) && is_numeric($plan['site_lng']) ? (float)$plan['site_lng'] : null,
                'fumigation_type' => $fum, 'enclosure_type' => ($plan['enclosure_type'] ?? '') !== '' ? (string)$plan['enclosure_type'] : null,
                'version' => 1, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            foreach ($ids as $cid) {
                $db->insert('ap_treatment_containers', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'consignment_id' => $cid]);
            }
            if ($req['status'] === 'confirmed') { self::advance($requestId, 'scheduled'); }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        ApAudit::event('treatment.created', $tid, ['request_id' => $requestId, 'containers' => count($ids)]);
        return $tid;
    }

    private static function throttle(string $ip): void
    {
        $db = ApDb::get();
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
        $n = (int)$db->fetchOne('SELECT COUNT(*) AS n FROM ap_intake_throttle WHERE ip = ? AND created_at >= ?', [$ip, $since])['n'];
        if ($n >= self::MAX_PER_IP_PER_HOUR) {
            throw new ApIntakeRejected('Too many requests from your network. Please try again later.');
        }
        $db->insert('ap_intake_throttle', ['id' => ApId::uuid(), 'ip' => mb_substr($ip, 0, 45), 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->delete('ap_intake_throttle', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    }

    /** AP-YYYYMM-NNNN, numbered within the month (UTC). */
    private static function nextReference(): string
    {
        $prefix = 'AP-' . gmdate('Ym') . '-';
        $last = ApDb::get()->fetchOne('SELECT reference FROM ap_requests WHERE reference LIKE ? ORDER BY reference DESC', [$prefix . '%']);
        $n = $last ? (int)substr((string)$last['reference'], -4) + 1 : 1;
        return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    private static function discardFile(string $fileId): void
    {
        try {
            $p = ApUploads::path($fileId);
            if ($p !== null && is_file($p)) { @unlink($p); }
        } catch (Throwable $e) {
        }
    }

    private static function notify(string $reference, string $name, string $email, int $containers, string $site): void
    {
        $h = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $staff = ApSettings::get('staff_email');
        $mails = [];
        if ($staff) {
            $mails[] = [$staff, "New fumigation request $reference",
                '<p>New request <b>' . $h($reference) . '</b> from ' . $h($name) . ' (' . $h($email) . ').</p><p>' . $containers . ' container(s) at ' . $h($site) . '.</p>'];
        }
        $mails[] = [$email, "We received your fumigation request $reference",
            '<p>Thank you, ' . $h($name) . '. We received your request <b>' . $h($reference) . '</b> for ' . $containers . ' container(s). Our team will confirm the schedule shortly.</p>'];
        foreach ($mails as [$to, $subject, $body]) {
            try {
                if (self::$mailer !== null) {
                    (self::$mailer)($to, $subject, $body);
                } elseif (class_exists('EmailService')) {
                    $r = (new EmailService())->send($to, $subject, $body, ['source' => 'allpest']);
                    if (empty($r['success'])) { EmailService::logSkipped($to, $subject, (string)($r['message'] ?? 'send failed'), 'allpest'); }
                }
            } catch (Throwable $e) {
                error_log('[allpest intake mail] ' . $e->getMessage()); // the request is already saved; email is best effort
            }
        }
    }

    private static function clip($v, int $n): string
    {
        return mb_substr(trim((string)$v), 0, $n);
    }

    private static function date($v): ?string
    {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
