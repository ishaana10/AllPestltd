<?php
declare(strict_types=1);

/**
 * The All Pest dashboard and reports are ordinary studio records, not code: dashboard tiles and charts are rows in
 * nu_dashboard_widgets (edited in the studio's widget designer) and the report tables are rows in nu_reports (edited in Reports). The dashboard holds only KPI tiles and charts; the tables live in Reports.
 * This class only seeds them the first time. A record that already exists is never touched, so a developer's changes survive updates.
 *
 * Widget SQL may use the studio macros {{days_ago_7|30|90|365}}, {{days_ahead_30}}, {{today}}, {{this_month}}, {{this_year}}.
 */
final class ApDashboard
{
    /** Roles whose dashboard shows these widgets. */
    public const ROLES = ['ap_admin', 'globeadmin'];

    private const FUMIG_FROM = 'FROM ap_treatment_containers tc JOIN ap_treatments t ON t.id = tc.treatment_id';

    /** @return array<int,array{key:string,type:string,title:string,icon:?string,width:int,height:int,config:array}> */
    public static function widgets(): array
    {
        $f = self::FUMIG_FROM;
        $w = static fn (string $key, string $type, string $title, int $width, int $height, array $config, ?string $icon = null): array =>
            ['key' => 'allpest.' . $key, 'type' => $type, 'title' => $title, 'icon' => $icon, 'width' => $width, 'height' => $height, 'config' => $config];
        return [
            $w('rofs_closed', 'stat', 'ROFs closed (90 days)', 1, 1, ['sql' => "SELECT COUNT(*) AS value FROM ap_treatments WHERE status = 'closed' AND closed_at >= '{{days_ago_90}}'", 'color' => 'primary'], 'hash'),
            $w('rofs_open', 'stat', 'ROFs still open', 1, 1, ['sql' => "SELECT COUNT(*) AS value FROM ap_treatments WHERE status <> 'closed'", 'color' => 'info', 'warn_at' => 1], 'activity'),
            $w('containers', 'stat', 'Containers treated (90 days)', 1, 1, ['sql' => "SELECT COUNT(*) AS value $f WHERE t.status = 'closed' AND t.closed_at >= '{{days_ago_90}}'", 'color' => 'primary'], 'hash'),
            $w('pass_rate', 'stat', 'Pass rate % (90 days)', 1, 1, ['sql' => "SELECT CASE WHEN COUNT(*) = 0 THEN 0 ELSE ROUND(100.0 * SUM(CASE WHEN tc.result = 'pass' THEN 1 ELSE 0 END) / COUNT(*)) END AS value $f WHERE t.status = 'closed' AND t.closed_at >= '{{days_ago_90}}'", 'color' => 'success'], 'gauge'),
            $w('failed', 'stat', 'Failed containers (90 days)', 1, 1, ['sql' => "SELECT COUNT(*) AS value $f WHERE t.status = 'closed' AND tc.result = 'fail' AND t.closed_at >= '{{days_ago_90}}'", 'color' => 'success', 'warn_at' => 1, 'error_at' => 3], 'bell'),
            $w('mb_used', 'stat', 'Methyl bromide used, kg (90 days)', 1, 1, ['sql' => "SELECT ROUND(COALESCE(SUM(COALESCE(applied_g, 0) + COALESCE(topup_g, 0)), 0) / 1000.0) AS value FROM ap_treatments WHERE status = 'closed' AND closed_at >= '{{days_ago_90}}'", 'color' => 'info'], 'bolt'),
            $w('stock_kg', 'stat', 'Gas in stock, kg', 1, 1, ['sql' => 'SELECT ROUND(COALESCE(SUM(grams), 0) / 1000.0) AS value FROM ap_stock_movements', 'color' => 'info'], 'progress'),
            $w('certs', 'stat', 'Certificates issued (90 days)', 1, 1, ['sql' => "SELECT COUNT(*) AS value FROM ap_certificates WHERE issued_at >= '{{days_ago_90}}'", 'color' => 'success'], 'file'),
            $w('cert_emails', 'stat', 'Certificate emails pending', 1, 1, ['sql' => 'SELECT COUNT(*) AS value FROM ap_certificates WHERE email_pending = 1', 'color' => 'success', 'warn_at' => 1], 'bell'),
            $w('invoices_draft', 'stat', 'Invoices awaiting finance', 1, 1, ['sql' => "SELECT COUNT(*) AS value FROM ap_invoices WHERE status IN ('draft', 'verified')", 'color' => 'info', 'warn_at' => 1, 'link_module' => 'allpest_invoices'], 'file'),
            $w('invoices_unposted', 'stat', 'Value not yet posted to ERPX', 1, 1, ['sql' => "SELECT COALESCE(SUM(total), 0) AS value FROM ap_invoices WHERE status <> 'posted'", 'color' => 'warning', 'link_module' => 'allpest_invoices'], 'trending'),
            $w('invoices_posted', 'stat', 'Value posted to ERPX (90 days)', 1, 1, ['sql' => "SELECT COALESCE(SUM(total), 0) AS value FROM ap_invoices WHERE status = 'posted' AND created_at >= '{{days_ago_90}}'", 'color' => 'success'], 'trending'),

            $w('chart_containers', 'chart_stacked', 'Containers treated per month', 2, 2, ['sql' => "SELECT SUBSTR(t.closed_at, 1, 7) AS label, SUM(CASE WHEN tc.result = 'pass' THEN 1 ELSE 0 END) AS Pass, SUM(CASE WHEN tc.result = 'fail' THEN 1 ELSE 0 END) AS Fail $f WHERE t.status = 'closed' AND t.closed_at >= '{{days_ago_365}}' GROUP BY SUBSTR(t.closed_at, 1, 7) ORDER BY label"]),
            $w('chart_mb', 'chart_bar', 'Methyl bromide used per month (kg)', 2, 2, ['sql' => "SELECT SUBSTR(closed_at, 1, 7) AS label, ROUND(SUM(COALESCE(applied_g, 0) + COALESCE(topup_g, 0)) / 1000.0) AS value FROM ap_treatments WHERE status = 'closed' AND closed_at >= '{{days_ago_365}}' GROUP BY SUBSTR(closed_at, 1, 7) ORDER BY label"]),
            $w('chart_invoices', 'chart_doughnut', 'Invoice value by status', 2, 2, ['sql' => 'SELECT status AS label, ROUND(SUM(total)) AS value FROM ap_invoices GROUP BY status ORDER BY status']),
            $w('chart_stock', 'chart_bar', 'Gas stock by lot (kg)', 2, 2, ['sql' => 'SELECT l.lot_ref AS label, ROUND(COALESCE(SUM(m.grams), 0) / 1000.0) AS value FROM ap_stock_lots l LEFT JOIN ap_stock_movements m ON m.lot_id = l.id GROUP BY l.lot_ref ORDER BY l.lot_ref']),
        ];
    }

    /** @return array<int,array{code:string,name:string,sql:string,filters:array}> */
    public static function reports(): array
    {
        $flt = static fn (string $field, string $label, string $op = 'LIKE'): array => ['field' => $field, 'label' => $label, 'type' => 'text', 'operator' => $op];
        $range = static fn (string $field): array => [
            ['field' => $field, 'param' => 'from', 'label' => 'From', 'type' => 'date', 'operator' => '>='],
            ['field' => $field, 'param' => 'to', 'label' => 'To', 'type' => 'date', 'operator' => '<='],
        ];
        return [
            ['code' => 'ap_fumigations', 'name' => 'All Pest: Fumigations (one row per container)', 'filters' => [...$range('closed_date'), $flt('result', 'Result', '='), $flt('customer', 'Customer'), $flt('technician', 'Technician')],
                'sql' => "SELECT SUBSTR(t.closed_at, 1, 10) AS closed_date, t.rof_no, r.reference AS request_no, r.customer_name AS customer, c.container_no, t.rof_type, tc.result, tc.tlv_ppm, t.dose_g, t.applied_g, tech.full_name AS technician, cert.number AS certificate_no
                          FROM ap_treatment_containers tc JOIN ap_treatments t ON t.id = tc.treatment_id JOIN ap_consignments c ON c.id = tc.consignment_id JOIN ap_requests r ON r.id = t.request_id
                          LEFT JOIN ap_technicians tech ON tech.id = t.technician_id LEFT JOIN ap_certificates cert ON cert.container_id = tc.id WHERE t.status = 'closed' ORDER BY t.closed_at DESC, t.rof_no, c.container_no"],
            ['code' => 'ap_inventory', 'name' => 'All Pest: Gas inventory by lot', 'filters' => [$flt('lot_ref', 'Lot')],
                'sql' => "SELECT l.lot_ref, l.description, ROUND(COALESCE(SUM(m.grams), 0), 1) AS balance_g, ROUND(COALESCE(SUM(CASE WHEN m.kind = 'receive' THEN m.grams ELSE 0 END), 0), 1) AS received_g,
                          ROUND(COALESCE(SUM(CASE WHEN m.kind = 'consume' THEN -m.grams ELSE 0 END), 0), 1) AS consumed_g, ROUND(COALESCE(SUM(CASE WHEN m.kind = 'adjust' THEN m.grams ELSE 0 END), 0), 1) AS adjusted_g,
                          COALESCE(SUM(CASE WHEN m.discrepancy = 1 THEN 1 ELSE 0 END), 0) AS discrepancies FROM ap_stock_lots l LEFT JOIN ap_stock_movements m ON m.lot_id = l.id GROUP BY l.id, l.lot_ref, l.description ORDER BY l.lot_ref"],
            ['code' => 'ap_stock_ledger', 'name' => 'All Pest: Stock ledger (every movement)', 'filters' => [...$range('created_date'), $flt('kind', 'Kind', '='), $flt('lot_ref', 'Lot')],
                'sql' => "SELECT m.created_at, SUBSTR(m.created_at, 1, 10) AS created_date, l.lot_ref, m.kind, m.grams, m.discrepancy, m.reason, m.user_id FROM ap_stock_movements m LEFT JOIN ap_stock_lots l ON l.id = m.lot_id ORDER BY m.created_at DESC"],
            ['code' => 'ap_invoices', 'name' => 'All Pest: Invoices', 'filters' => [...$range('created_date'), $flt('status', 'Status', '='), $flt('customer', 'Customer')],
                'sql' => "SELECT i.invoice_no, SUBSTR(i.created_at, 1, 10) AS created_date, r.reference AS request_no, i.customer_name AS customer, i.status, i.subtotal, i.tax_amount, i.discount, i.total, i.erpx_invoice_no, i.verified_by, i.posted_at, i.last_error
                          FROM ap_invoices i JOIN ap_requests r ON r.id = i.request_id ORDER BY i.created_at DESC"],
            ['code' => 'ap_certificates', 'name' => 'All Pest: Certificates', 'filters' => [...$range('issued_date'), $flt('container_no', 'Container')],
                'sql' => "SELECT cert.number, SUBSTR(cert.issued_at, 1, 10) AS issued_date, cert.issued_at, c.container_no, t.rof_no, r.reference AS request_no, cert.emailed_at, cert.email_pending
                          FROM ap_certificates cert JOIN ap_treatment_containers tc ON tc.id = cert.container_id JOIN ap_consignments c ON c.id = tc.consignment_id JOIN ap_treatments t ON t.id = cert.treatment_id JOIN ap_requests r ON r.id = t.request_id ORDER BY cert.number DESC"],
            ['code' => 'ap_technicians', 'name' => 'All Pest: Technicians and registrations', 'filters' => [$flt('full_name', 'Technician')],
                'sql' => "SELECT tech.full_name, tech.registration_no, tech.registration_expiry, tech.status, (SELECT COUNT(*) FROM ap_treatments x WHERE x.technician_id = tech.id AND x.status = 'closed') AS rofs_closed FROM ap_technicians tech ORDER BY tech.full_name"],
        ];
    }

    /** Add any missing widgets and reports. Never overwrites an existing record. @return array{widgets:int,reports:int} how many were added */
    public static function provision(object $db, int $projectId = 1): array
    {
        $added = ['widgets' => 0, 'reports' => 0];
        $pos = 100;
        try {
            foreach (self::ROLES as $role) {
                foreach (self::widgets() as $w) {
                    $pos += 10;
                    if ($db->fetchOne('SELECT widget_id FROM nu_dashboard_widgets WHERE widget_role = ? AND widget_key = ?', [$role, $w['key']])) { continue; }
                    $db->insert('nu_dashboard_widgets', ['widget_user_id' => null, 'widget_role' => $role, 'widget_key' => $w['key'], 'widget_type' => $w['type'], 'widget_title' => $w['title'], 'widget_icon' => $w['icon'],
                        'widget_config' => json_encode($w['config'], JSON_UNESCAPED_SLASHES), 'widget_width' => $w['width'], 'widget_height' => $w['height'], 'widget_position' => $pos, 'widget_active' => 1]);
                    $added['widgets']++;
                }
            }
        } catch (Throwable $e) {
            error_log('[allpest dashboard widgets] ' . $e->getMessage());
        }
        try {
            foreach (self::reports() as $r) {
                if ($db->fetchOne('SELECT report_id FROM nu_reports WHERE report_code = ? AND project_id = ?', [$r['code'], $projectId])) { continue; }
                $db->insert('nu_reports', ['project_id' => $projectId, 'report_code' => $r['code'], 'report_name' => $r['name'], 'report_type' => 'table', 'report_view_mode' => 'table',
                    'report_sql' => trim((string)preg_replace('/\s+/', ' ', $r['sql'])), 'report_columns' => '[]', 'report_filters' => json_encode($r['filters']), 'report_settings' => '{}', 'report_active' => 1]);
                $added['reports']++;
            }
        } catch (Throwable $e) {
            error_log('[allpest dashboard reports] ' . $e->getMessage());
        }
        return $added;
    }
}
