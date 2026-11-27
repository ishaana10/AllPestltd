<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApForms', 'ApDashboard'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}
require_once dirname(__DIR__, 3) . '/core/WidgetSql.php';
require_once dirname(__DIR__, 3) . '/core/WidgetRegistry.php';

final class ApDashboardTest extends TestCase
{
    private ApTestDb $db;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->db->exec('CREATE TABLE nu_dashboard_widgets (widget_id INTEGER PRIMARY KEY AUTOINCREMENT, widget_user_id TEXT NULL, widget_role TEXT, widget_type TEXT NOT NULL, widget_title TEXT NOT NULL DEFAULT "", widget_icon TEXT NULL, widget_config TEXT NOT NULL, widget_width INTEGER NOT NULL DEFAULT 1, widget_height INTEGER NOT NULL DEFAULT 1, widget_position INTEGER NOT NULL DEFAULT 0, widget_active INTEGER NOT NULL DEFAULT 1, widget_key TEXT NULL, widget_locked INTEGER NOT NULL DEFAULT 0, widget_refresh INTEGER NOT NULL DEFAULT 0)');
        $this->db->exec('CREATE TABLE nu_reports (report_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL DEFAULT 1, report_code TEXT NOT NULL, report_name TEXT NOT NULL, report_type TEXT DEFAULT "table", report_view_mode TEXT DEFAULT "table", report_sql TEXT, report_columns TEXT, report_filters TEXT, report_settings TEXT, report_active INTEGER DEFAULT 1)');
        NuWidgetRegistry::reset();
    }

    private function seedData(): void
    {
        $now = date('Y-m-d H:i:s');
        $rid = ApId::uuid(); $tid = ApId::uuid(); $con = ApId::uuid(); $tc = ApId::uuid(); $tech = ApId::uuid(); $lot = ApId::uuid();
        $this->db->insert('ap_technicians', ['id' => $tech, 'full_name' => 'Demo Technician', 'registration_no' => 'R1', 'registration_expiry' => '2020-01-01', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('ap_requests', ['id' => $rid, 'reference' => 'AP-1', 'status' => 'completed', 'customer_name' => 'Pacific Timber', 'customer_email' => 'a@b.c', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('ap_treatments', ['id' => $tid, 'request_id' => $rid, 'rof_type' => 'general', 'status' => 'closed', 'rof_no' => 'AP-1-1', 'technician_id' => $tech, 'applied_g' => 40000, 'closed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('ap_consignments', ['id' => $con, 'request_id' => $rid, 'container_no' => 'MSKU1', 'created_at' => $now]);
        $this->db->insert('ap_treatment_containers', ['id' => $tc, 'treatment_id' => $tid, 'consignment_id' => $con, 'result' => 'pass']);
        $this->db->insert('ap_certificates', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'container_id' => $tc, 'number' => 'AP-2026-00001', 'content_sha256' => str_repeat('a', 64), 'issued_at' => $now, 'created_at' => $now]);
        $this->db->insert('ap_stock_lots', ['id' => $lot, 'lot_ref' => 'LOT-1', 'description' => 'MB', 'mb_fraction' => 1, 'created_at' => $now]);
        $this->db->insert('ap_stock_movements', ['id' => ApId::uuid(), 'lot_id' => $lot, 'kind' => 'receive', 'grams' => 3000, 'discrepancy' => 0, 'user_id' => 'u', 'created_at' => $now]);
        $this->db->insert('ap_invoices', ['id' => ApId::uuid(), 'request_id' => $rid, 'invoice_no' => 'AP-INV-2026-00001', 'status' => 'draft', 'total' => 690, 'customer_name' => 'X', 'created_at' => $now, 'updated_at' => $now]);
    }

    public function testSqlMacrosGiveRollingDates(): void
    {
        $sql = NuWidgetSql::substitute("a '{{days_ago_90}}' b '{{days_ago_30}}' c '{{days_ahead_30}}' d '{{days_ago_365}}' e '{{days_ago_7}}'", 'u', 'r');
        $this->assertStringContainsString("'" . date('Y-m-d', strtotime('-90 days')) . "'", $sql);
        $this->assertStringContainsString("'" . date('Y-m-d', strtotime('+30 days')) . "'", $sql);
        $this->assertStringNotContainsString('{{', $sql);
    }

    public function testEveryWidgetUsesARegisteredStudioTypeWithValidSql(): void
    {
        $keys = [];
        foreach (ApDashboard::widgets() as $w) {
            $this->assertNotNull(NuWidgetRegistry::get($w['type']), $w['type'] . ' must be a studio widget type');
            $this->assertMatchesRegularExpression('/^SELECT\b/i', $w['config']['sql'], $w['key']);
            $keys[] = $w['key'];
        }
        $this->assertSame($keys, array_values(array_unique($keys)), 'widget keys are unique');
        $this->assertNotNull(NuWidgetRegistry::get('chart_stacked'));
    }

    public function testEveryWidgetAndReportQueryRunsAndReturnsRows(): void
    {
        $this->seedData();
        foreach (ApDashboard::widgets() as $w) {
            $rows = $this->db->fetchAll(NuWidgetSql::substitute($w['config']['sql'], 'u', 'ap_admin'));
            $this->assertNotEmpty($rows, $w['key'] . ' returned nothing for sample data');
            if (in_array($w['type'], ['chart_bar', 'chart_stacked', 'chart_doughnut'], true)) { $this->assertArrayHasKey('label', $rows[0], $w['key']); }
            if ($w['type'] === 'chart_bar' || $w['type'] === 'chart_doughnut') { $this->assertArrayHasKey('value', $rows[0], $w['key']); }
        }
        foreach (ApDashboard::reports() as $r) {
            $rows = $this->db->fetchAll(rtrim(trim($r['sql']), ';'));
            $this->assertNotEmpty($rows, $r['code'] . ' returned nothing');
            foreach ($r['filters'] as $f) { $this->assertArrayHasKey($f['field'], $rows[0], $r['code'] . ' filter column ' . $f['field']); }
        }
    }

    public function testStatValuesAreCorrect(): void
    {
        $this->seedData();
        $by = [];
        foreach (ApDashboard::widgets() as $w) { $by[$w['key']] = $this->db->fetchAll(NuWidgetSql::substitute($w['config']['sql'], 'u', 'r')); }
        $this->assertEquals(1, $by['allpest.rofs_closed'][0]['value']);
        $this->assertEquals(100, $by['allpest.pass_rate'][0]['value']);
        $this->assertEquals(0, $by['allpest.failed'][0]['value']);
        $this->assertEquals(40, $by['allpest.mb_used'][0]['value']);
        $this->assertEquals(3, $by['allpest.stock_kg'][0]['value']);
        $this->assertEquals(1, $by['allpest.invoices_draft'][0]['value']);
        $this->assertSame('LOT-1', $by['allpest.table_low_stock'][0]['Lot']);                     // 3,000 g is under 5 kg
        $this->assertSame('Demo Technician', $by['allpest.table_registrations'][0]['Technician']); // registration expired in 2020
    }

    public function testProvisionSeedsBothRolesAndReportsOnceAndNeverOverwritesDeveloperEdits(): void
    {
        $a = ApDashboard::provision($this->db, 1);
        $this->assertSame(count(ApDashboard::widgets()) * count(ApDashboard::ROLES), $a['widgets']);
        $this->assertSame(count(ApDashboard::reports()), $a['reports']);
        // a developer changes a tile and removes nothing else
        $this->db->exec("UPDATE nu_dashboard_widgets SET widget_title = 'My title', widget_config = '{\"sql\":\"SELECT 7 AS value\"}' WHERE widget_key = 'allpest.rofs_open' AND widget_role = 'ap_admin'");
        $this->db->exec("UPDATE nu_reports SET report_sql = 'SELECT 1' WHERE report_code = 'ap_invoices'");
        $b = ApDashboard::provision($this->db, 1);
        $this->assertSame(['widgets' => 0, 'reports' => 0], $b);
        $this->assertSame('My title', $this->db->fetchOne("SELECT widget_title FROM nu_dashboard_widgets WHERE widget_key = 'allpest.rofs_open' AND widget_role = 'ap_admin'")['widget_title']);
        $this->assertSame('SELECT 1', $this->db->fetchOne("SELECT report_sql FROM nu_reports WHERE report_code = 'ap_invoices'")['report_sql']);
        // a deleted tile comes back on the next provision, other roles are unaffected
        $this->db->exec("DELETE FROM nu_dashboard_widgets WHERE widget_key = 'allpest.certs' AND widget_role = 'globeadmin'");
        $this->assertSame(1, ApDashboard::provision($this->db, 1)['widgets']);
    }

    public function testMenuUsesTheStudioDashboardAndReportsAndTheHardCodedModuleIsGone(): void
    {
        $root = dirname(__DIR__, 3);
        $m = ApForms::moduleEntries();
        $this->assertArrayHasKey('dashboard', $m);
        $this->assertArrayHasKey('reports', $m);
        $this->assertFalse(isset($m['allpest_dashboard']));
        $this->assertFalse(is_dir($root . '/modules/allpest_dashboard'));
        $this->assertFalse(is_file($root . '/api/allpest/reports.php'));
        $js = (string)file_get_contents($root . '/assets/js/nu-widgets.js');
        $this->assertTrue(str_contains($js, 'cfg.series') && str_contains($js, 'stacked'), 'the chart renderer draws multi-series stacked bars');
    }
}
