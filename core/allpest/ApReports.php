<?php
declare(strict_types=1);

require_once __DIR__ . '/ApDb.php';

/**
 * Read-only numbers for the All Pest dashboard and reports. Every figure is computed from the live tables, so the screens, CSV exports
 * and the audit trail always agree. Ranges are UTC datetimes; a treatment counts in the period it was closed.
 */
final class ApReports
{
    private static function utc(DateTimeImmutable $d): string { return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }

    /** @return array<string,int|float> */
    public static function summary(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $db = ApDb::get();
        $p = [self::utc($from), self::utc($to)];
        $closed = $db->fetchOne("SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(applied_g, 0) + COALESCE(topup_g, 0)), 0) AS g FROM ap_treatments WHERE status = 'closed' AND closed_at >= ? AND closed_at <= ?", $p);
        $res = $db->fetchAll("SELECT tc.result AS r, COUNT(*) AS n FROM ap_treatment_containers tc JOIN ap_treatments t ON t.id = tc.treatment_id WHERE t.status = 'closed' AND t.closed_at >= ? AND t.closed_at <= ? GROUP BY tc.result", $p);
        $by = array_column($res, 'n', 'r');
        $pass = (int)($by['pass'] ?? 0);
        $fail = (int)($by['fail'] ?? 0);
        $reqs = $db->fetchOne('SELECT COUNT(*) AS n FROM ap_requests WHERE created_at >= ? AND created_at <= ?', $p);
        $certs = $db->fetchOne('SELECT COUNT(*) AS n FROM ap_certificates WHERE issued_at >= ? AND issued_at <= ?', $p);
        $pending = $db->fetchOne('SELECT COUNT(*) AS n FROM ap_certificates WHERE email_pending = 1');
        $open = $db->fetchOne("SELECT COUNT(*) AS n FROM ap_treatments WHERE status <> 'closed'");
        $inv = $db->fetchOne("SELECT COALESCE(SUM(CASE WHEN status = 'posted' THEN total ELSE 0 END), 0) AS posted, COALESCE(SUM(CASE WHEN status <> 'posted' THEN total ELSE 0 END), 0) AS unposted FROM ap_invoices WHERE created_at >= ? AND created_at <= ?", $p);
        $total = $pass + $fail;
        return [
            'requests' => (int)$reqs['n'], 'rofs_closed' => (int)$closed['n'], 'containers' => $total, 'pass' => $pass, 'fail' => $fail,
            'pass_rate' => $total > 0 ? round($pass * 100 / $total, 1) : 0.0, 'mb_used_g' => (float)$closed['g'],
            'certificates_issued' => (int)$certs['n'], 'certificate_emails_pending' => (int)$pending['n'], 'rofs_open' => (int)$open['n'],
            'invoiced_posted' => (float)$inv['posted'], 'invoiced_unposted' => (float)$inv['unposted'],
        ];
    }

    /** One row per month with container results and gas used. @return array<int,array{month:string,pass:int,fail:int,rofs:int,mb_g:float}> */
    public static function monthly(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $db = ApDb::get();
        $p = [self::utc($from), self::utc($to)];
        $out = [];
        foreach ($db->fetchAll("SELECT SUBSTR(t.closed_at, 1, 7) AS m, tc.result AS r, COUNT(*) AS n FROM ap_treatment_containers tc JOIN ap_treatments t ON t.id = tc.treatment_id WHERE t.status = 'closed' AND t.closed_at >= ? AND t.closed_at <= ? GROUP BY SUBSTR(t.closed_at, 1, 7), tc.result", $p) as $r) {
            $out[$r['m']] ??= ['month' => $r['m'], 'pass' => 0, 'fail' => 0, 'rofs' => 0, 'mb_g' => 0.0];
            if (in_array($r['r'], ['pass', 'fail'], true)) { $out[$r['m']][$r['r']] += (int)$r['n']; }
        }
        foreach ($db->fetchAll("SELECT SUBSTR(closed_at, 1, 7) AS m, COUNT(*) AS n, COALESCE(SUM(COALESCE(applied_g, 0) + COALESCE(topup_g, 0)), 0) AS g FROM ap_treatments WHERE status = 'closed' AND closed_at >= ? AND closed_at <= ? GROUP BY SUBSTR(closed_at, 1, 7)", $p) as $r) {
            $out[$r['m']] ??= ['month' => $r['m'], 'pass' => 0, 'fail' => 0, 'rofs' => 0, 'mb_g' => 0.0];
            $out[$r['m']]['rofs'] = (int)$r['n'];
            $out[$r['m']]['mb_g'] = (float)$r['g'];
        }
        ksort($out);
        return array_values($out);
    }

    /** One row per container treated in the range. @return array<int,array<string,mixed>> */
    public static function fumigations(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return ApDb::get()->fetchAll(
            "SELECT t.closed_at, t.rof_no, r.reference AS request_no, r.customer_name AS customer, c.container_no, t.rof_type, tc.result, tc.tlv_ppm,
                    t.dose_g, t.applied_g, tech.full_name AS technician, cert.number AS certificate_no
             FROM ap_treatment_containers tc
             JOIN ap_treatments t ON t.id = tc.treatment_id
             JOIN ap_consignments c ON c.id = tc.consignment_id
             JOIN ap_requests r ON r.id = t.request_id
             LEFT JOIN ap_technicians tech ON tech.id = t.technician_id
             LEFT JOIN ap_certificates cert ON cert.container_id = tc.id
             WHERE t.status = 'closed' AND t.closed_at >= ? AND t.closed_at <= ? ORDER BY t.closed_at, t.rof_no, c.container_no",
            [self::utc($from), self::utc($to)]
        );
    }

    /**
     * Gas stock: balance and flows per lot, with a flag for lots under the threshold, plus total use in the range.
     * @return array{lots:array<int,array<string,mixed>>,total_balance_g:float,consumed_in_range_g:float,threshold_g:float}
     */
    public static function inventory(DateTimeImmutable $from, DateTimeImmutable $to, float $thresholdG): array
    {
        $db = ApDb::get();
        $lots = [];
        $total = 0.0;
        foreach ($db->fetchAll('SELECT id, lot_ref, description FROM ap_stock_lots ORDER BY lot_ref') as $l) {
            $m = $db->fetchOne("SELECT COALESCE(SUM(grams), 0) AS bal, COALESCE(SUM(CASE WHEN kind = 'receive' THEN grams ELSE 0 END), 0) AS rec, COALESCE(SUM(CASE WHEN kind = 'consume' THEN -grams ELSE 0 END), 0) AS con,
                COALESCE(SUM(CASE WHEN kind = 'adjust' THEN grams ELSE 0 END), 0) AS adj, COALESCE(SUM(CASE WHEN discrepancy = 1 THEN 1 ELSE 0 END), 0) AS disc FROM ap_stock_movements WHERE lot_id = ?", [$l['id']]);
            $bal = (float)$m['bal'];
            $total += $bal;
            $lots[] = ['lot_ref' => $l['lot_ref'], 'description' => $l['description'], 'balance' => $bal, 'received_g' => (float)$m['rec'], 'consumed_g' => (float)$m['con'],
                'adjusted_g' => (float)$m['adj'], 'discrepancies' => (int)$m['disc'], 'low' => $bal < $thresholdG];
        }
        $use = $db->fetchOne("SELECT COALESCE(-SUM(grams), 0) AS g FROM ap_stock_movements WHERE kind = 'consume' AND created_at >= ? AND created_at <= ?", [self::utc($from), self::utc($to)]);
        return ['lots' => $lots, 'total_balance_g' => $total, 'consumed_in_range_g' => (float)$use['g'], 'threshold_g' => $thresholdG];
    }

    /** Stock movements in the range, newest first (the inventory ledger). @return array<int,array<string,mixed>> */
    public static function movements(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return ApDb::get()->fetchAll('SELECT m.created_at, l.lot_ref, m.kind, m.grams, m.discrepancy, m.reason, m.user_id FROM ap_stock_movements m LEFT JOIN ap_stock_lots l ON l.id = m.lot_id WHERE m.created_at >= ? AND m.created_at <= ? ORDER BY m.created_at DESC', [self::utc($from), self::utc($to)]);
    }

    /** @return array{rows:array<int,array<string,mixed>>,by_status:array<string,array{count:int,total:float}>,unposted_total:float,unposted_over_7_days:int} */
    public static function invoices(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array
    {
        $rows = ApDb::get()->fetchAll('SELECT i.invoice_no, r.reference AS request_no, i.customer_name AS customer, i.status, i.total, i.erpx_invoice_no, i.created_at, i.posted_at FROM ap_invoices i JOIN ap_requests r ON r.id = i.request_id WHERE i.created_at >= ? AND i.created_at <= ? ORDER BY i.created_at DESC', [self::utc($from), self::utc($to)]);
        $by = []; $unposted = 0.0; $old = 0;
        $cut = $now->modify('-7 days')->format('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $by[$r['status']] ??= ['count' => 0, 'total' => 0.0];
            $by[$r['status']]['count']++;
            $by[$r['status']]['total'] += (float)$r['total'];
            if ($r['status'] !== 'posted') { $unposted += (float)$r['total']; if ((string)$r['created_at'] <= $cut) { $old++; } }
        }
        return ['rows' => $rows, 'by_status' => $by, 'unposted_total' => $unposted, 'unposted_over_7_days' => $old];
    }

    /** @return array<int,array<string,mixed>> */
    public static function certificates(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return ApDb::get()->fetchAll('SELECT cert.number, cert.issued_at, cert.emailed_at, cert.email_pending, c.container_no, t.rof_no, r.reference AS request_no FROM ap_certificates cert JOIN ap_treatment_containers tc ON tc.id = cert.container_id JOIN ap_consignments c ON c.id = tc.consignment_id JOIN ap_treatments t ON t.id = cert.treatment_id JOIN ap_requests r ON r.id = t.request_id WHERE cert.issued_at >= ? AND cert.issued_at <= ? ORDER BY cert.number', [self::utc($from), self::utc($to)]);
    }

    /** Technicians with registration status (valid, expiring within 30 days, expired, none) and ROFs closed. @return array<int,array<string,mixed>> */
    public static function technicians(DateTimeImmutable $now): array
    {
        $db = ApDb::get();
        $today = $now->format('Y-m-d');
        $soon = $now->modify('+30 days')->format('Y-m-d');
        $out = [];
        foreach ($db->fetchAll('SELECT id, full_name, registration_no, registration_expiry, status FROM ap_technicians ORDER BY full_name') as $t) {
            $exp = (string)($t['registration_expiry'] ?? '');
            $reg = ($exp === '' || $exp === '0000-00-00') ? 'none' : ($exp < $today ? 'expired' : ($exp <= $soon ? 'expiring' : 'valid'));
            $n = $db->fetchOne("SELECT COUNT(*) AS n FROM ap_treatments WHERE technician_id = ? AND status = 'closed'", [$t['id']]);
            $out[] = ['full_name' => $t['full_name'], 'registration_no' => $t['registration_no'], 'registration_expiry' => $exp, 'registration' => $reg, 'status' => $t['status'], 'rofs_closed' => (int)$n['n']];
        }
        return $out;
    }

    /** CSV text. Cells starting with = + - @ are prefixed with ' so a spreadsheet never runs them as formulas. */
    public static function toCsv(array $rows): string
    {
        if (!$rows) { return ''; }
        $cell = static function ($v): string {
            $s = $v === null ? '' : (string)$v;
            if ($s !== '' && strpos('=+-@', $s[0]) !== false && !is_numeric($s)) { $s = "'" . $s; }
            return preg_match('/[",\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        $lines = [implode(',', array_map($cell, array_keys($rows[0])))];
        foreach ($rows as $r) { $lines[] = implode(',', array_map($cell, array_values($r))); }
        return implode("\r\n", $lines) . "\r\n";
    }
}
