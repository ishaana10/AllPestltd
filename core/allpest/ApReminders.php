<?php
declare(strict_types=1);

require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApSettings.php';
require_once __DIR__ . '/ApStock.php';

/** What the admin should be nagged about: registrations, equipment audits/calibration, low gas stock, unsigned closed treatments. */
final class ApReminders
{
    public const WINDOW_DAYS = 30;
    public const SIGNATURE_GRACE_HOURS = 24;

    private static $mailer = null;

    public static function setMailer(?callable $m): void { self::$mailer = $m; }

    /** @return array<int,array{kind:string,subject:string,due:string}> */
    public static function due(DateTimeImmutable $now): array
    {
        $db = ApDb::get();
        $limit = $now->modify('+' . self::WINDOW_DAYS . ' days')->format('Y-m-d');
        $out = [];

        foreach ($db->fetchAll("SELECT full_name, registration_expiry FROM ap_technicians WHERE status = 'active' AND registration_expiry IS NOT NULL AND registration_expiry <= ? ORDER BY registration_expiry", [$limit]) as $t) {
            $out[] = ['kind' => 'registration', 'subject' => 'Registration of ' . $t['full_name'] . ' expires', 'due' => (string)$t['registration_expiry']];
        }
        foreach ($db->fetchAll("SELECT name, next_audit_due, expiry_date FROM ap_equipment WHERE status = 'in_service' AND ((next_audit_due IS NOT NULL AND next_audit_due <= ?) OR (expiry_date IS NOT NULL AND expiry_date <= ?)) ORDER BY name", [$limit, $limit]) as $e) {
            $dates = array_filter([$e['next_audit_due'], $e['expiry_date']], fn ($d) => $d !== null && $d <= $limit);
            $out[] = ['kind' => 'equipment', 'subject' => $e['name'] . ' audit/calibration due', 'due' => (string)min($dates)];
        }
        $threshold = (float)ApSettings::get('stock.low_threshold_g', '7000');
        foreach (ApStock::lowStock($threshold) as $l) {
            $out[] = ['kind' => 'stock', 'subject' => 'Methyl bromide lot ' . $l['lot_ref'] . ' is low (' . rtrim(rtrim(number_format((float)$l['balance'], 2, '.', ''), '0'), '.') . ' g)', 'due' => $now->format('Y-m-d')];
        }
        $cut = $now->modify('-' . self::SIGNATURE_GRACE_HOURS . ' hours')->format('Y-m-d H:i:s');
        foreach ($db->fetchAll("SELECT id, version, closed_at FROM ap_treatments WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at <= ? ORDER BY closed_at", [$cut]) as $t) {
            $have = array_column($db->fetchAll('SELECT role FROM ap_signatures WHERE treatment_id = ? AND version = ?', [$t['id'], $t['version']]), 'role');
            $missing = array_values(array_diff(['fumigator', 'officer'], $have));
            if ($missing) {
                $out[] = ['kind' => 'signature', 'subject' => 'Treatment ' . $t['id'] . ' awaiting ' . implode(' and ', $missing) . ' signature',
                    'due' => (new DateTimeImmutable((string)$t['closed_at'], new DateTimeZone('UTC')))->modify('+' . self::SIGNATURE_GRACE_HOURS . ' hours')->format('Y-m-d')];
            }
        }
        return $out;
    }

    /** Email the staff address, at most once per Fiji day, and only when something is due. @return bool sent */
    public static function sendDaily(DateTimeImmutable $now): bool
    {
        $to = trim((string)ApSettings::get('staff_email', ''));
        if ($to === '') { return false; }
        $day = $now->setTimezone(new DateTimeZone('Pacific/Fiji'))->format('Y-m-d');
        if (ApSettings::get('reminders.last_sent', '') === $day) { return false; }
        $items = self::due($now);
        if (!$items) { return false; }
        $body = '<p>These need attention:</p><ul>';
        foreach ($items as $i) { $body .= '<li>' . htmlspecialchars($i['subject'] . ' (' . $i['due'] . ')') . '</li>'; }
        $body .= '</ul>';
        $subject = 'All Pest: ' . count($items) . ' item(s) need attention';
        try {
            if (self::$mailer !== null) {
                (self::$mailer)($to, $subject, $body);
            } elseif (class_exists('EmailService')) {
                $r = (new EmailService())->send($to, $subject, $body, ['source' => 'allpest']);
                if (empty($r['success'])) { return false; }
            } else {
                return false;
            }
        } catch (Throwable $e) {
            error_log('[allpest reminders] ' . $e->getMessage());
            return false;
        }
        ApSettings::set('reminders.last_sent', $day);
        return true;
    }
}
