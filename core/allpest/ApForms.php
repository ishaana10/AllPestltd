<?php
declare(strict_types=1);

require_once __DIR__ . '/ApSettings.php';
require_once __DIR__ . '/ApDashboard.php';

/**
 * Provisions the All Pest screens as ordinary StudioX forms (nu_forms) and sidebar entries (nu_menus), so they are built, edited,
 * permissioned and audited by the studio itself. Idempotent: missing forms are created, and a form still carrying the layout this app last wrote is
 * refreshed; one edited in the Forms builder is left alone. Forms over tables whose rules live in the All Pest engine (treatments, certificates,
 * invoices, stock movements) are read-only: changes to them go through the engine so the checks and the audit trail stay intact.
 */
final class ApForms
{
    public const GROUP_LABEL = 'All Pest';
    private const ADMIN = 'globeadmin,ap_admin';
    private const FIELD = 'globeadmin,ap_admin,ap_technician';

    private static function t(string $name, string $label, int $col = 6, array $more = []): array { return ['name' => $name, 'label' => $label, 'type' => 'text', 'col' => $col] + $more; }
    private static function req(array $f): array { return $f + ['required' => true]; }
    private static function ro(array $f): array { return $f + ['readonly' => true]; }
    private static function sel(string $name, string $label, array $opts, int $col = 6, array $more = []): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'select', 'col' => $col, 'options' => array_map(fn ($k, $v) => ['value' => (string)$k, 'label' => $v], array_keys($opts), $opts)] + $more;
    }
    private static function lookup(string $name, string $label, string $table, string $valueCol, string $labelCol, int $col = 6, array $more = []): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'select', 'col' => $col, 'options_source' => 'table', 'options_table' => $table, 'options_value_col' => $valueCol,
            'options_label_col' => $labelCol, 'select2' => true] + $more;
    }
    private static function num(string $name, string $label, int $col = 6, array $more = []): array { return ['name' => $name, 'label' => $label, 'type' => 'number', 'col' => $col, 'step' => 'any'] + $more; }
    private static function dt(string $name, string $label, string $type = 'datetime', int $col = 6, array $more = []): array { return ['name' => $name, 'label' => $label, 'type' => $type, 'col' => $col] + $more; }
    private static function area(string $name, string $label, int $col = 12, array $more = []): array { return ['name' => $name, 'label' => $label, 'type' => 'textarea', 'col' => $col, 'rows' => 3] + $more; }
    private static function row(array ...$fields): array { return ['type' => 'row', 'children' => $fields]; }
    private static function col(string $field, string $label, string $width = '160px', string $fmt = 'text', string $align = 'left'): array
    {
        return ['fieldname' => $field, 'fieldlabel' => $label, 'width' => $width, 'align' => $align, 'formatter' => $fmt, 'sortable' => true];
    }

    /** @return array<string,array{table:string,name:string,description:string,layout:array,browse:array,roles:string,readonly:bool,order:int}> */
    public static function definitions(): array
    {
        $status = ['active' => 'Active', 'inactive' => 'Inactive'];
        $yn = ['1' => 'Yes', '0' => 'No'];
        return [
            'ap_requests' => ['table' => 'ap_requests', 'name' => 'Fumigation Requests', 'description' => 'Customer requests (public form or staff entry), with their containers.', 'roles' => self::ADMIN, 'readonly' => false, 'order' => 10,
                'layout' => [
                    self::row(self::req(self::t('customer_name', 'Customer name')), self::req(['name' => 'customer_email', 'label' => 'Customer email', 'type' => 'email', 'col' => 6])),
                    self::row(self::t('customer_phone', 'Phone'), self::t('customer_tin', 'TIN')),
                    self::row(self::area('customer_address', 'Customer address')),
                    self::row(self::area('site_address', 'Site address (where the treatment is done)')),
                    self::row(self::dt('requested_date', 'Requested date', 'date'), self::ro(self::sel('status', 'Status (changed from the Request Board)', ['new' => 'New', 'confirmed' => 'Confirmed', 'scheduled' => 'Scheduled', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'], 6, ['default_value' => 'new']))),
                    self::row(['name' => 'reference', 'label' => 'Reference', 'type' => 'autonumber', 'col' => 6, 'sequence_code' => 'ap_staff_request_ref', 'prefix_pattern' => 'AP-S-', 'suffix_pattern' => '', 'padding_length' => 5], self::t('job_type', 'Job type', 6, ['default_value' => 'fumigation', 'readonly' => true])),
                    self::row(self::area('notes', 'Notes')),
                    self::row(self::area('container_list', 'Containers as submitted on the public form (imported into the grid below once)', 12, ['readonly' => true])),
                    ['type' => 'subform', 'name' => 'consignments', 'label' => 'Containers / consignments', 'col' => 12, 'subform' => ['form_code' => 'ap_consignments', 'fk_field' => 'request_id', 'view' => 'grid']],
                ],
                'browse' => [self::col('reference', 'Reference', '140px'), self::col('customer_name', 'Customer', '200px'), self::col('status', 'Status', '120px', 'badge', 'center'), self::col('requested_date', 'Requested', '120px'), self::col('site_address', 'Site', '240px')]],
            'ap_public_request' => ['table' => 'ap_requests', 'name' => 'Request a Fumigation (public form)', 'description' => 'The form customers fill in through the public link. Its fields are the same table as Fumigation Requests.', 'roles' => self::ADMIN, 'readonly' => false, 'order' => 0, 'type' => 'public',
                'layout' => [
                    self::row(self::req(self::t('customer_name', 'Your name / company')), self::req(['name' => 'customer_email', 'label' => 'Email', 'type' => 'email', 'col' => 6])),
                    self::row(self::t('customer_phone', 'Phone'), self::t('customer_tin', 'TIN (for the invoice)')),
                    self::row(self::area('customer_address', 'Billing address')),
                    self::row(self::req(self::area('site_address', 'Where is the fumigation to take place?'))),
                    self::row(self::dt('requested_date', 'Preferred date', 'date')),
                    self::row(self::req(self::area('container_list', 'Containers (one per line: container no, seal no, commodity, origin, destination, quantity)', 12, ['rows' => 6, 'help' => 'Example: MSKU1234567, SL12345, Timber, Auckland, Lautoka, 1 x 40ft']))),
                    self::row(self::area('notes', 'Anything else we should know')),
                    self::row(['name' => 'reference', 'label' => 'Reference', 'type' => 'autonumber', 'col' => 6, 'sequence_code' => 'ap_public_request_ref', 'prefix_pattern' => 'AP-W-', 'suffix_pattern' => '', 'padding_length' => 5]),
                ],
                'browse' => [self::col('reference', 'Reference', '140px'), self::col('customer_name', 'Customer', '200px'), self::col('requested_date', 'Requested', '120px')]],
            'ap_consignments' => ['table' => 'ap_consignments', 'name' => 'Containers', 'description' => 'One row per container or consignment on a request.', 'roles' => self::ADMIN, 'readonly' => false, 'order' => 0, 'type' => 'subform',
                'layout' => [
                    self::row(self::t('container_no', 'Container no.', 4), self::t('seal_no', 'Seal no.', 4), self::t('quantity', 'Quantity', 4)),
                    self::row(self::t('commodity', 'Commodity', 4), self::t('origin', 'Origin', 4), self::t('destination', 'Destination', 4)),
                ],
                'browse' => [self::col('container_no', 'Container', '140px'), self::col('seal_no', 'Seal', '120px'), self::col('commodity', 'Commodity', '180px'), self::col('origin', 'Origin', '120px'), self::col('destination', 'Destination', '140px')]],
            'ap_treatments' => ['table' => 'ap_treatments', 'name' => 'Treatments (ROF)', 'description' => 'Records of fumigation. Dose, readings, signing and closing are done through the treatment actions so the rules are enforced.', 'roles' => self::ADMIN, 'readonly' => true, 'order' => 20,
                'layout' => [
                    self::row(self::ro(self::t('status', 'Status')), self::ro(self::t('rof_type', 'ROF type'))),
                    self::row(self::ro(self::lookup('technician_id', 'Fumigator in charge', 'ap_technicians', 'id', 'full_name')), self::ro(self::lookup('officer_id', 'Supervising officer', 'ap_officers', 'id', 'full_name'))),
                    self::row(self::ro(self::lookup('schedule_id', 'Treatment schedule', 'ap_schedules', 'id', 'name')), self::ro(self::dt('scheduled_start', 'Scheduled start'))),
                    self::row(self::ro(self::num('volume_m3', 'Volume (m3)')), self::ro(self::num('dose_rate_used', 'Dose rate used (g/m3)'))),
                    self::row(self::ro(self::num('dose_g', 'Dose (g)')), self::ro(self::num('applied_g', 'Applied (g)'))),
                    self::row(self::ro(self::dt('injected_at', 'Injected at')), self::ro(self::dt('start_point_at', 'Start point'))),
                    self::row(self::ro(self::dt('ventilation_at', 'Ventilation started')), self::ro(self::t('result', 'Result'))),
                ],
                'browse' => [self::col('status', 'Status', '120px', 'badge', 'center'), self::col('rof_type', 'Type', '100px'), self::col('scheduled_start', 'Scheduled', '160px'), self::col('result', 'Result', '90px', 'badge', 'center'), self::col('version', 'Version', '80px', 'text', 'right')]],
            'ap_certificates' => ['table' => 'ap_certificates', 'name' => 'Certificates', 'description' => 'Issued fumigation certificates (one per passing container).', 'roles' => self::ADMIN, 'readonly' => true, 'order' => 30,
                'layout' => [
                    self::row(self::ro(self::t('number', 'Certificate number')), self::ro(self::dt('issued_at', 'Issued'))),
                    self::row(self::ro(self::dt('emailed_at', 'Emailed')), self::ro(self::t('email_pending', 'Email pending'))),
                    self::row(self::ro(self::t('content_sha256', 'Content SHA-256', 12))),
                ],
                'browse' => [self::col('number', 'Number', '160px'), self::col('issued_at', 'Issued', '160px'), self::col('emailed_at', 'Emailed', '160px'), self::col('email_pending', 'Pending', '90px', 'text', 'center')]],
            'ap_invoice_links' => ['table' => 'ap_invoice_links', 'name' => 'Invoices (ERPX)', 'description' => 'The ERPX invoice raised for each request.', 'roles' => self::ADMIN, 'readonly' => true, 'order' => 40,
                'layout' => [
                    self::row(self::ro(self::t('state', 'State')), self::ro(self::t('erpx_invoice_no', 'ERPX invoice no.'))),
                    self::row(self::ro(self::t('attempts', 'Attempts')), self::ro(self::dt('next_attempt_at', 'Next attempt'))),
                    self::row(self::ro(self::area('last_error', 'Last error'))),
                ],
                'browse' => [self::col('erpx_invoice_no', 'ERPX invoice', '160px'), self::col('state', 'State', '120px', 'badge', 'center'), self::col('attempts', 'Attempts', '90px', 'text', 'right'), self::col('last_error', 'Last error', '300px')]],
            'ap_technicians' => ['table' => 'ap_technicians', 'name' => 'Technicians', 'description' => 'Fumigators. Link each to their StudioX user account so they can sign in and sign.', 'roles' => self::ADMIN, 'readonly' => false, 'order' => 50,
                'layout' => [
                    self::row(self::req(self::t('full_name', 'Full name')), self::lookup('user_id', 'StudioX user account', 'nu_users', 'usr_id', 'usr_username')),
                    self::row(self::t('registration_no', 'Registration no.'), self::dt('registration_expiry', 'Registration expiry', 'date')),
                    self::row(self::t('accreditation_no', 'Accreditation no.'), self::sel('status', 'Status', $status, 6, ['default_value' => 'active'])),
                ],
                'browse' => [self::col('full_name', 'Name', '200px'), self::col('registration_no', 'Registration', '140px'), self::col('registration_expiry', 'Expires', '120px'), self::col('status', 'Status', '100px', 'badge', 'center')]],
            'ap_officers' => ['table' => 'ap_officers', 'name' => 'Supervising Officers', 'description' => 'Biosecurity officers who sign off treatments (they sign on the tablet, no account needed).', 'roles' => self::ADMIN, 'readonly' => false, 'order' => 60,
                'layout' => [self::row(self::req(self::t('full_name', 'Full name')), self::t('designation', 'Designation')), self::row(self::sel('status', 'Status', $status, 6, ['default_value' => 'active']))],
                'browse' => [self::col('full_name', 'Name', '200px'), self::col('designation', 'Designation', '200px'), self::col('status', 'Status', '100px', 'badge', 'center')]],
            'ap_equipment' => ['table' => 'ap_equipment', 'name' => 'Equipment', 'description' => 'Detectors, gauges and other equipment used on treatments.', 'roles' => self::ADMIN, 'readonly' => false, 'order' => 70,
                'layout' => [
                    self::row(self::req(self::sel('kind', 'Kind', ['detector' => 'Gas detector', 'thermometer' => 'Thermometer', 'fan' => 'Fan', 'other' => 'Other'])), self::req(self::t('name', 'Name'))),
                    self::row(self::t('serial_no', 'Serial no.'), self::sel('status', 'Status', ['in_service' => 'In service', 'out_of_service' => 'Out of service', 'retired' => 'Retired'], 6, ['default_value' => 'in_service'])),
                    self::row(self::dt('next_audit_due', 'Next audit due', 'date'), self::dt('expiry_date', 'Expiry date', 'date')),
                    self::row(self::area('notes', 'Notes')),
                ],
                'browse' => [self::col('name', 'Name', '200px'), self::col('kind', 'Kind', '120px'), self::col('serial_no', 'Serial', '140px'), self::col('status', 'Status', '120px', 'badge', 'center'), self::col('next_audit_due', 'Audit due', '120px')]],
            'ap_schedules' => ['table' => 'ap_schedules', 'name' => 'Treatment Schedules', 'description' => 'Dose rate, exposure time and rules for each treatment (the standards tables themselves are built in).', 'roles' => self::ADMIN, 'readonly' => false, 'order' => 80,
                'layout' => [
                    self::row(self::req(self::t('name', 'Schedule name')), self::req(self::sel('treatment_type', 'Type', ['general' => 'General', 'perishable' => 'Perishable']))),
                    self::row(self::req(self::num('dose_rate', 'Dose rate (g/m3)')), self::req(self::num('exposure_hours', 'Exposure (hours)'))),
                    self::row(self::req(self::num('min_temp_c', 'Minimum temperature (C)', 6, ['default_value' => 21])), self::sel('retention_class', 'Retention class', ['standard' => '70% standard', 'eighty' => '80% (Table 5)'], 6, ['default_value' => 'standard'])),
                    self::row(self::sel('compensation_allowed', 'Dose compensation allowed', $yn, 4, ['default_value' => '0']), self::sel('topups_allowed', 'Top-ups allowed', $yn, 4, ['default_value' => '1']), self::sel('active', 'Active', $yn, 4, ['default_value' => '1'])),
                    self::row(self::area('notes', 'Notes')),
                ],
                'browse' => [self::col('name', 'Schedule', '200px'), self::col('treatment_type', 'Type', '100px'), self::col('dose_rate', 'Dose g/m3', '100px', 'text', 'right'), self::col('exposure_hours', 'Hours', '80px', 'text', 'right'), self::col('active', 'Active', '80px', 'text', 'center')]],
            'ap_stock_lots' => ['table' => 'ap_stock_lots', 'name' => 'Gas Lots', 'description' => 'Methyl bromide lots. Receive stock and record stocktakes with the stock actions so balances stay correct.', 'roles' => self::ADMIN, 'readonly' => false, 'order' => 90,
                'layout' => [self::row(self::req(self::t('lot_ref', 'Lot reference')), self::req(self::num('mb_fraction', 'Methyl bromide fraction (0-1)', 6, ['default_value' => 1]))), self::row(self::t('description', 'Description', 12))],
                'browse' => [self::col('lot_ref', 'Lot', '160px'), self::col('description', 'Description', '260px'), self::col('mb_fraction', 'MB fraction', '110px', 'text', 'right')]],
            'ap_stock_movements' => ['table' => 'ap_stock_movements', 'name' => 'Stock Movements', 'description' => 'Every receipt, use and stocktake adjustment (read-only).', 'roles' => self::ADMIN, 'readonly' => true, 'order' => 100,
                'layout' => [
                    self::row(self::ro(self::lookup('lot_id', 'Lot', 'ap_stock_lots', 'id', 'lot_ref')), self::ro(self::t('kind', 'Kind'))),
                    self::row(self::ro(self::num('grams', 'Grams')), self::ro(self::dt('created_at', 'When'))),
                    self::row(self::ro(self::area('reason', 'Reason'))),
                ],
                'browse' => [self::col('created_at', 'When', '160px'), self::col('kind', 'Kind', '90px'), self::col('grams', 'Grams', '110px', 'text', 'right'), self::col('reason', 'Reason', '280px')]],
        ];
    }

    /**
     * Create any missing All Pest forms and menu entries; bring forms the builder has not touched up to the current layout.
     * A form whose layout still matches the one this app last wrote (kept as a hash in the settings) is refreshed; one that was
     * edited in the builder is left alone. @return string[] the form codes created this run
     */
    public static function provision(object $db, int $projectId = 1, ?int $userId = null): array
    {
        $created = [];
        foreach (self::definitions() as $code => $d) {
            $have = $db->fetchOne('SELECT form_id, form_layout, browse_layout FROM nu_forms WHERE form_code = ? AND project_id = ?', [$code, $projectId]);
            if ($have) {
                $new = json_encode($d['layout']) . '|' . json_encode($d['browse']);
                $key = 'forms.layout_hash.' . $code;
                $stored = (string)ApSettings::get($key, '');
                $now = md5((string)$have['form_layout'] . '|' . (string)$have['browse_layout']);
                if (($stored === '' || $stored === $now) && $now !== md5($new)) {
                    $db->update('nu_forms', ['form_layout' => json_encode($d['layout']), 'browse_layout' => json_encode($d['browse'])], 'form_id = ?', [$have['form_id']]);
                }
                if ($stored === $now || $stored === '' || $now === md5($new)) { ApSettings::set($key, md5($new)); }
                continue;
            }
            ApSettings::set('forms.layout_hash.' . $code, md5(json_encode($d['layout']) . '|' . json_encode($d['browse'])));
            $db->insert('nu_forms', [
                'project_id' => $projectId, 'form_code' => $code, 'form_type' => in_array($d['type'] ?? 'main', ['subform'], true) ? 'subform' : 'main', 'form_name' => $d['name'], 'form_table' => $d['table'], 'form_description' => $d['description'],
                'form_layout' => json_encode($d['layout']), 'browse_layout' => json_encode($d['browse']), 'form_active' => 1, 'form_pk_type' => 'uuid', 'form_table_mode' => 'existing',
                'browse_delete_enabled' => $d['readonly'] ? 0 : 1,
            ] + ($code === 'ap_requests' ? ['form_custom_php' => self::importHook()] : []));
            $created[] = $code;
        }
        self::provisionMenu($db, $projectId);
        ApDashboard::provision($db, $projectId);
        return $created;
    }

    /** Sidebar entries that open a studio module rather than a form: target => [label, order, roles]. */
    public static function moduleEntries(): array
    {
        return ['allpest_work' => ['Treatment Workspace', 5, self::FIELD], 'allpest_rof' => ['Record of Fumigation', 4, self::FIELD], 'allpest_board' => ['Request Board', 6, self::ADMIN], 'allpest_stock' => ['Stock', 7, self::ADMIN], 'allpest_invoices' => ['Invoices', 8, self::ADMIN], 'dashboard' => ['Dashboard', 3, self::ADMIN], 'reports' => ['Reports', 9, self::ADMIN], 'allpest_settings' => ['Settings', 90, self::ADMIN]];
    }

    /** PHP run when the staff request form is opened: bring in the containers of any new public requests first. */
    private static function importHook(): string
    {
        $file = var_export(__DIR__ . '/ApRequests.php', true);
        return "try { require_once $file; ApRequests::importContainerLists(); } catch (Throwable \$e) { error_log('[allpest import] ' . \$e->getMessage()); }";
    }

    /** Create the public link (once) for the public request form. @return string|null the share token */
    public static function provisionPublicShare(object $db, int $projectId = 1, ?string $userId = null, string $notifyEmail = ''): ?string
    {
        $row = $db->fetchOne('SELECT fs_token FROM nu_form_shares WHERE project_id = ? AND fs_form_code = ?', [$projectId, 'ap_public_request']);
        if ($row) { return (string)$row['fs_token']; }
        $token = bin2hex(random_bytes(16));
        $fields = ['customer_name', 'customer_email', 'customer_phone', 'customer_tin', 'customer_address', 'site_address', 'requested_date', 'container_list', 'notes'];
        $db->insert('nu_form_shares', [
            'project_id' => $projectId, 'fs_form_code' => 'ap_public_request', 'fs_token' => $token, 'fs_secret' => bin2hex(random_bytes(24)), 'fs_enabled' => 1,
            'fs_title' => 'Request a fumigation', 'fs_intro' => 'Tell us where and what to treat. We will confirm the booking by email.', 'fs_submit_label' => 'Send request',
            'fs_success_message' => 'Thank you. Your request has been received and our team will be in touch to confirm.', 'fs_notify_email' => $notifyEmail !== '' ? $notifyEmail : null,
            'fs_confirm_email' => 1, 'fs_email_field' => 'customer_email', 'fs_confirm_message' => 'We have your fumigation request and will confirm the booking shortly.',
            'fs_fields' => json_encode($fields), 'fs_created_by' => $userId !== null && $userId !== '' ? substr($userId, 0, 32) : null,
        ]);
        return $token;
    }

    private static function provisionMenu(object $db, int $projectId): void
    {
        $base = ['menu_type' => 'group', 'menu_icon' => 'clipboard', 'menu_browse_mode' => 'inline', 'menu_preview_mode' => 'inline', 'menu_default_view' => 'browse', 'menu_active' => 1, 'project_id' => $projectId];
        // The first release added a single module entry that opened standalone pages: turn it into the group.
        $old = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'allpest' AND project_id = ?", [$projectId]);
        $group = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = ? AND menu_type = 'group' AND project_id = ?", [self::GROUP_LABEL, $projectId]);
        if ($old && !$group) {
            $db->update('nu_menus', ['menu_type' => 'group', 'menu_target' => '', 'menu_roles' => self::FIELD, 'menu_role_access' => json_encode(explode(',', self::FIELD))], 'menu_id = ?', [$old['menu_id']]);
            $groupId = (int)$old['menu_id'];
        } elseif ($group) {
            $groupId = (int)$group['menu_id'];
        } else {
            $groupId = (int)$db->insert('nu_menus', $base + ['menu_label' => self::GROUP_LABEL, 'menu_target' => '', 'menu_parent_id' => 0, 'menu_order' => 20, 'menu_roles' => self::FIELD, 'menu_role_access' => json_encode(explode(',', self::FIELD))]);
            if (!$groupId) { $groupId = (int)$db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = ? AND menu_type = 'group' AND project_id = ?", [self::GROUP_LABEL, $projectId])['menu_id']; }
        }
        // The first releases showed 'Treatments (ROF)' to technicians, but the studio form refuses them: they use the Record of Fumigation page.
        $db->update('nu_menus', ['menu_roles' => self::ADMIN, 'menu_role_access' => json_encode(explode(',', self::ADMIN))], "menu_target = 'ap_treatments' AND project_id = ? AND menu_roles = ?", [$projectId, self::FIELD]);
        foreach (self::definitions() as $code => $d) {
            if (in_array($d['type'] ?? 'main', ['subform', 'public'], true)) { continue; }
            if ($db->fetchOne('SELECT menu_id FROM nu_menus WHERE menu_target = ? AND project_id = ?', [$code, $projectId])) { continue; }
            $db->insert('nu_menus', ['menu_label' => $d['name'], 'menu_type' => 'form', 'menu_target' => $code, 'menu_parent_id' => $groupId, 'menu_order' => $d['order'], 'menu_roles' => $d['roles'],
                'menu_role_access' => json_encode(explode(',', $d['roles'])), 'menu_active' => 1, 'menu_icon' => 'form', 'menu_open_mode' => 'inline|browse', 'menu_browse_mode' => 'inline',
                'menu_preview_mode' => 'inline', 'menu_default_view' => 'browse', 'project_id' => $projectId]);
        }
        // The first dashboard was a hard-coded module; it is now studio widgets and Reports, so drop its old menu entry.
        $db->query("DELETE FROM nu_menus WHERE menu_target = 'allpest_dashboard' AND project_id = ?", [$projectId]);
        foreach (self::moduleEntries() as $target => [$label, $order, $roles]) {
            if ($db->fetchOne('SELECT menu_id FROM nu_menus WHERE menu_target = ? AND project_id = ? AND menu_parent_id = ?', [$target, $projectId, $groupId])) { continue; }
            $db->insert('nu_menus', ['menu_label' => $label, 'menu_type' => 'module', 'menu_target' => $target, 'menu_parent_id' => $groupId, 'menu_order' => $order, 'menu_roles' => $roles,
                'menu_role_access' => json_encode(explode(',', $roles)), 'menu_active' => 1, 'menu_icon' => 'clipboard', 'menu_open_mode' => 'inline|browse', 'menu_browse_mode' => 'inline',
                'menu_preview_mode' => 'inline', 'menu_default_view' => 'browse', 'project_id' => $projectId]);
        }
    }
}
