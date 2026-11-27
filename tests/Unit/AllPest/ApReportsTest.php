<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApStock', 'ApReports'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApReportsTest extends TestCase
{
    private ApTestDb $db;
    private string $tech;
    private string $lot;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->tech = ApId::uuid();
        $this->db->insert('ap_technicians', ['id' => $this->tech, 'full_name' => 'Demo Technician', 'registration_no' => 'R1', 'registration_expiry' => '2027-12-31', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->lot = ApId::uuid();
        $this->db->insert('ap_stock_lots', ['id' => $this->lot, 'lot_ref' => 'LOT-1', 'description' => 'MB', 'mb_fraction' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->mov('receive', 100000, '2026-01-02 00:00:00');
    }

    private function mov(string $kind, float $g, string $at, int $disc = 0): void
    {
        $this->db->insert('ap_stock_movements', ['id' => ApId::uuid(), 'lot_id' => $this->lot, 'kind' => $kind, 'grams' => $g, 'discrepancy' => $disc, 'user_id' => 'u', 'created_at' => $at]);
    }

    /** One request with one closed ROF and one container. @return string treatment id */
    private function rof(string $ref, string $closedAt, ?string $result, float $applied, string $containerNo, string $status = 'closed'): string
    {
        $rid = ApId::uuid(); $tid = ApId::uuid(); $con = ApId::uuid();
        $this->db->insert('ap_requests', ['id' => $rid, 'reference' => $ref, 'status' => 'completed', 'customer_name' => 'Pacific Timber', 'customer_email' => 'a@b.c', 'created_at' => $closedAt, 'updated_at' => $closedAt]);
        $this->db->insert('ap_treatments', ['id' => $tid, 'request_id' => $rid, 'rof_type' => 'general', 'status' => $status, 'rof_no' => $ref . '-1', 'technician_id' => $this->tech, 'applied_g' => $applied, 'dose_g' => $applied, 'closed_at' => $status === 'closed' ? $closedAt : null, 'created_at' => $closedAt, 'updated_at' => $closedAt]);
        $this->db->insert('ap_consignments', ['id' => $con, 'request_id' => $rid, 'container_no' => $containerNo, 'created_at' => $closedAt]);
        $this->db->insert('ap_treatment_containers', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'consignment_id' => $con, 'result' => $result, 'tlv_ppm' => 3.0]);
        return $tid;
    }

    private function range(): array { return [new DateTimeImmutable('2026-11-01 00:00:00 UTC'), new DateTimeImmutable('2026-11-30 23:59:59 UTC')]; }

    public function testSummaryCountsOnlyTheRangeAndComputesPassRate(): void
    {
        $this->rof('AP-1', '2026-11-10 01:00:00', 'pass', 40000, 'C1');
        $this->rof('AP-2', '2026-11-12 01:00:00', 'pass', 30000, 'C2');
        $this->rof('AP-3', '2026-11-13 01:00:00', 'fail', 20000, 'C3');
        $this->rof('AP-4', '2026-10-13 01:00:00', 'pass', 99999, 'C4');           // outside the range
        $this->rof('AP-5', '2026-11-14 01:00:00', null, 5000, 'C5', 'exposure');   // not closed
        [$f, $t] = $this->range();
        $s = ApReports::summary($f, $t);
        $this->assertSame(3, $s['rofs_closed']);
        $this->assertSame(3, $s['containers']);
        $this->assertSame(2, $s['pass']);
        $this->assertSame(1, $s['fail']);
        $this->assertEqualsWithDelta(66.7, $s['pass_rate'], 0.05);
        $this->assertEqualsWithDelta(90000.0, $s['mb_used_g'], 0.001);
        $this->assertSame(4, $s['requests']);   // AP-4 was created in October
    }

    public function testMonthlySeriesGroupsByMonthWithResults(): void
    {
        $this->rof('AP-1', '2026-10-10 01:00:00', 'pass', 1000, 'C1');
        $this->rof('AP-2', '2026-11-10 01:00:00', 'fail', 2000, 'C2');
        $m = ApReports::monthly(new DateTimeImmutable('2026-10-01 00:00:00 UTC'), new DateTimeImmutable('2026-11-30 23:59:59 UTC'));
        $this->assertSame(['2026-10', '2026-11'], array_column($m, 'month'));
        $this->assertSame(1, $m[0]['pass']);
        $this->assertSame(1, $m[1]['fail']);
        $this->assertEqualsWithDelta(2000.0, $m[1]['mb_g'], 0.001);
    }

    public function testFumigationReportListsEveryContainerWithTechnicianAndCertificate(): void
    {
        $tid = $this->rof('AP-1', '2026-11-10 01:00:00', 'pass', 40000, 'MSKU1');
        $cid = $this->db->fetchOne('SELECT id FROM ap_treatment_containers WHERE treatment_id = ?', [$tid])['id'];
        $this->db->insert('ap_certificates', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'container_id' => $cid, 'number' => 'AP-2026-00001', 'content_sha256' => str_repeat('a', 64), 'issued_at' => '2026-11-11 00:00:00', 'created_at' => '2026-11-11 00:00:00']);
        [$f, $t] = $this->range();
        $rows = ApReports::fumigations($f, $t);
        $this->assertCount(1, $rows);
        $this->assertSame('MSKU1', $rows[0]['container_no']);
        $this->assertSame('AP-1-1', $rows[0]['rof_no']);
        $this->assertSame('Demo Technician', $rows[0]['technician']);
        $this->assertSame('AP-2026-00001', $rows[0]['certificate_no']);
        $this->assertSame('pass', $rows[0]['result']);
    }

    public function testInventoryShowsBalancesFlowsAndDiscrepancies(): void
    {
        $this->mov('consume', -40000, '2026-11-10 00:00:00');
        $this->mov('adjust', -500, '2026-11-20 00:00:00', 1);
        [$f, $t] = $this->range();
        $inv = ApReports::inventory($f, $t, 70000.0);
        $lot = $inv['lots'][0];
        $this->assertSame('LOT-1', $lot['lot_ref']);
        $this->assertEqualsWithDelta(59500.0, $lot['balance'], 0.001);
        $this->assertEqualsWithDelta(100000.0, $lot['received_g'], 0.001);
        $this->assertEqualsWithDelta(40000.0, $lot['consumed_g'], 0.001);
        $this->assertSame(1, $lot['discrepancies']);
        $this->assertTrue($lot['low']);                       // 59,500 g is under the 70,000 g threshold
        $this->assertEqualsWithDelta(59500.0, $inv['total_balance_g'], 0.001);
        $this->assertEqualsWithDelta(40000.0, $inv['consumed_in_range_g'], 0.001);
    }

    public function testInvoiceReportTotalsByStatusAndFlagsOldUnposted(): void
    {
        $rid = ApId::uuid();
        $this->db->insert('ap_requests', ['id' => $rid, 'reference' => 'AP-9', 'status' => 'completed', 'customer_name' => 'X', 'customer_email' => 'a@b.c', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $this->db->insert('ap_invoices', ['id' => ApId::uuid(), 'request_id' => $rid, 'invoice_no' => 'AP-INV-2026-00001', 'status' => 'draft', 'total' => 690, 'customer_name' => 'X', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $rid2 = ApId::uuid();
        $this->db->insert('ap_requests', ['id' => $rid2, 'reference' => 'AP-10', 'status' => 'completed', 'customer_name' => 'Y', 'customer_email' => 'a@b.c', 'created_at' => '2026-11-20 00:00:00', 'updated_at' => '2026-11-20 00:00:00']);
        $this->db->insert('ap_invoices', ['id' => ApId::uuid(), 'request_id' => $rid2, 'invoice_no' => 'AP-INV-2026-00002', 'status' => 'posted', 'total' => 300, 'customer_name' => 'Y', 'erpx_invoice_no' => 'INV-7', 'created_at' => '2026-11-20 00:00:00', 'updated_at' => '2026-11-20 00:00:00']);
        [$f, $t] = $this->range();
        $r = ApReports::invoices($f, $t, new DateTimeImmutable('2026-11-27 00:00:00 UTC'));
        $this->assertCount(2, $r['rows']);
        $this->assertEqualsWithDelta(690.0, $r['by_status']['draft']['total'], 0.001);
        $this->assertSame(1, $r['by_status']['posted']['count']);
        $this->assertEqualsWithDelta(690.0, $r['unposted_total'], 0.001);
        $this->assertSame(1, $r['unposted_over_7_days']);
    }

    public function testTechnicianReportFlagsExpiredAndSoonToExpireRegistrations(): void
    {
        $this->db->insert('ap_technicians', ['id' => ApId::uuid(), 'full_name' => 'Old Reg', 'registration_no' => 'R2', 'registration_expiry' => '2026-11-10', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->db->insert('ap_technicians', ['id' => ApId::uuid(), 'full_name' => 'Soon Reg', 'registration_no' => 'R3', 'registration_expiry' => '2026-12-10', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $rows = ApReports::technicians(new DateTimeImmutable('2026-11-27 00:00:00 UTC'));
        $by = array_column($rows, 'registration', 'full_name');
        $this->assertSame('expired', $by['Old Reg']);
        $this->assertSame('expiring', $by['Soon Reg']);
        $this->assertSame('valid', $by['Demo Technician']);
    }

    public function testCsvQuotesCommasAndGuardsAgainstSpreadsheetFormulas(): void
    {
        $csv = ApReports::toCsv([['name' => 'A, B', 'note' => '=SUM(1)', 'n' => 5], ['name' => 'He said "hi"', 'note' => '', 'n' => 0]]);
        $this->assertTrue(str_contains($csv, "name,note,n\r\n"));
        $this->assertTrue(str_contains($csv, '"A, B"'));
        $this->assertTrue(str_contains($csv, "'=SUM(1)"));
        $this->assertTrue(str_contains($csv, '"He said ""hi"""'));
    }

    public function testApiAndDashboardAreWiredAndRestrictedToAdmins(): void
    {
        $root = dirname(__DIR__, 3);
        $api = (string)file_get_contents($root . '/api/allpest/reports.php');
        $mod = (string)file_get_contents($root . '/modules/allpest_dashboard/allpest_dashboard.php');
        $this->assertTrue(str_contains($api, "guard('admin.reports')"));
        foreach (['summary', 'monthly', 'fumigations', 'inventory', 'invoices', 'technicians', 'audit', 'csv'] as $w) { $this->assertTrue(str_contains($api, $w), $w); }
        $this->assertTrue(str_contains($mod, "'admin.reports'"));
        $this->assertFalse(str_contains($mod, 'alert('));
    }
}
