<?php
declare(strict_types=1);
/**
 * Admin API: dashboard numbers and reports. GET ?report=summary|monthly|fumigations|inventory|movements|invoices|certificates|technicians|audit
 * &from=YYYY-MM-DD&to=YYYY-MM-DD (Fiji dates; default last 90 days) [&format=csv].
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApReports'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('admin.reports');

$fiji = new DateTimeZone('Pacific/Fiji');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$day = static fn (string $s, DateTimeImmutable $dflt): DateTimeImmutable => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? new DateTimeImmutable($s . ' 00:00:00', $GLOBALS['fiji']) : $dflt;
$from = $day((string)($_GET['from'] ?? ''), $now->setTimezone($fiji)->modify('-90 days')->setTime(0, 0));
$to = $day((string)($_GET['to'] ?? ''), $now->setTimezone($fiji))->setTime(23, 59, 59);
$report = (string)($_GET['report'] ?? 'summary');

try {
    switch ($report) {
        case 'summary': $data = ApReports::summary($from, $to); break;
        case 'monthly': $data = ApReports::monthly($from, $to); break;
        case 'fumigations': $data = ApReports::fumigations($from, $to); break;
        case 'inventory':
            $data = ApReports::inventory($from, $to, (float)ApSettings::get('stock.low_threshold_g', '5000'));
            if (($_GET['format'] ?? '') === 'csv') { $data = $data['lots']; }
            break;
        case 'movements': $data = ApReports::movements($from, $to); break;
        case 'invoices':
            $data = ApReports::invoices($from, $to, $now);
            if (($_GET['format'] ?? '') === 'csv') { $data = $data['rows']; }
            break;
        case 'certificates': $data = ApReports::certificates($from, $to); break;
        case 'technicians': $data = ApReports::technicians($now); break;
        case 'audit':
            require_once dirname(__DIR__, 2) . '/core/allpest/ApAudit.php';
            $a = ApAudit::audit();
            $rows = $a->getLogs(['from' => $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'to' => $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'q' => 'ap_'], 500, 0);
            $rows = array_values(array_filter($rows, static fn ($r) => str_starts_with((string)$r['audit_table'], 'ap_')));
            $data = ['chain' => $a->verifyChain(), 'rows' => array_map(static fn ($r) => ['when' => $r['audit_timestamp'], 'user' => $r['audit_username'], 'action' => $r['audit_action'], 'summary' => $r['audit_summary'], 'status' => $r['audit_status']], $rows)];
            if (($_GET['format'] ?? '') === 'csv') { $data = $data['rows']; }
            break;
        default: http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error' => 'Unknown report']); exit;
    }
    if (($_GET['format'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="allpest-' . $report . '-' . $now->setTimezone($fiji)->format('Ymd') . '.csv"');
        echo "\xEF\xBB\xBF" . ApReports::toCsv(array_is_list($data) ? $data : [$data]);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode($data);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
