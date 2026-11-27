<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApSettings.php';
require_once __DIR__ . '/ApUploads.php';

class ApIntakeRejected extends RuntimeException
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

        self::notify($reference, $name, $email, count($cons), $site);
        return ['request_id' => $requestId, 'reference' => $reference];
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
