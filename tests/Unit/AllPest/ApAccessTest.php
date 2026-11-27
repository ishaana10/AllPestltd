<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
require_once dirname(__DIR__, 3) . '/core/SecurityGate.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApAccess'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApAccessTest extends TestCase
{
    private ApTestDb $db;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->db->exec('CREATE TABLE nu_users (usr_id TEXT PRIMARY KEY, usr_role TEXT, usr_active INTEGER DEFAULT 1)');
        $this->db->exec('CREATE TABLE nu_roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_code TEXT UNIQUE, role_name TEXT, role_description TEXT DEFAULT "", role_active INTEGER DEFAULT 1, role_is_system INTEGER DEFAULT 0, tenant_code TEXT DEFAULT "default")');
        $this->db->exec('CREATE TABLE nu_permissions (perm_id INTEGER PRIMARY KEY AUTOINCREMENT, perm_code TEXT UNIQUE, perm_name TEXT, perm_category TEXT, perm_description TEXT)');
        $this->db->exec('CREATE TABLE nu_role_permissions (rp_id INTEGER PRIMARY KEY AUTOINCREMENT, rp_role_id INTEGER, rp_perm_id INTEGER, UNIQUE (rp_role_id, rp_perm_id))');
        $this->db->exec('CREATE TABLE nu_menus (menu_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER DEFAULT 1, menu_label TEXT, menu_type TEXT, menu_target TEXT, menu_parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, menu_roles TEXT, menu_role_access TEXT, menu_active INTEGER DEFAULT 1, menu_icon TEXT, menu_open_mode TEXT, menu_browse_mode TEXT, menu_preview_mode TEXT, menu_default_view TEXT)');
        ApAccess::install($this->db, 1);
        foreach ([['u-tech1', 'ap_technician'], ['u-tech2', 'ap_technician'], ['u-admin', 'ap_admin'], ['u-glob', 'globeadmin'], ['u-other', 'user']] as [$id, $role]) {
            $this->db->insert('nu_users', ['usr_id' => $id, 'usr_role' => $role]);
        }
        $this->tech1 = ApId::uuid();
        $this->tech2 = ApId::uuid();
        $this->db->insert('ap_technicians', ['id' => $this->tech1, 'user_id' => 'u-tech1', 'full_name' => 'One', 'created_at' => '2099-01-01 00:00:00']);
        $this->db->insert('ap_technicians', ['id' => $this->tech2, 'user_id' => 'u-tech2', 'full_name' => 'Two', 'created_at' => '2099-01-01 00:00:00']);
        $this->mine = $this->treatmentFor($this->tech1);
        $this->theirs = $this->treatmentFor($this->tech2);
    }

    private string $tech1;
    private string $tech2;
    private string $mine;
    private string $theirs;

    private function treatmentFor(string $tech): string
    {
        $r = ApId::uuid();
        $this->db->insert('ap_requests', ['id' => $r, 'reference' => 'R' . substr($r, 0, 8), 'customer_name' => 'C', 'customer_email' => 'c@x.com', 'created_at' => '2099-01-01 00:00:00']);
        $t = ApId::uuid();
        $this->db->insert('ap_treatments', ['id' => $t, 'request_id' => $r, 'technician_id' => $tech, 'created_at' => '2099-01-01 00:00:00']);
        return $t;
    }

    public function testTechnicianActsOnOwnTreatmentOnly(): void
    {
        foreach (['treatment.view', 'treatment.record', 'treatment.sign'] as $a) {
            $this->assertTrue(ApAccess::can('u-tech1', $a, $this->mine), "own $a");
            $this->assertFalse(ApAccess::can('u-tech1', $a, $this->theirs), "other $a");
        }
        $this->assertFalse(ApAccess::can('u-tech1', 'treatment.record', ApId::uuid()), 'unknown treatment');
    }

    public function testTechnicianCannotCallAdminActions(): void
    {
        foreach (['admin.stock', 'admin.technicians', 'admin.invoices', 'admin.requests', 'admin.audit_pack'] as $a) {
            $this->assertFalse(ApAccess::can('u-tech1', $a), $a);
        }
    }

    public function testAdminsCanDoEverything(): void
    {
        foreach (['u-admin', 'u-glob'] as $u) {
            $this->assertTrue(ApAccess::can($u, 'admin.stock'));
            $this->assertTrue(ApAccess::can($u, 'treatment.record', $this->theirs));
            $this->assertTrue(ApAccess::can($u, 'treatment.view', $this->mine));
        }
    }

    public function testOutsidersAndUnknownActionsAreRefused(): void
    {
        $this->assertFalse(ApAccess::can('u-other', 'treatment.view', $this->mine));
        $this->assertFalse(ApAccess::can('u-other', 'admin.stock'));
        $this->assertFalse(ApAccess::can('nobody', 'treatment.view', $this->mine));
        $this->assertFalse(ApAccess::can('', 'treatment.view'));
        $this->assertFalse(ApAccess::can('u-admin', 'something.else'));
    }

    public function testInactiveTechnicianIsRefused(): void
    {
        $this->db->update('ap_technicians', ['status' => 'inactive'], 'id = ?', [$this->tech1]);
        $this->assertFalse(ApAccess::can('u-tech1', 'treatment.record', $this->mine));
    }

    public function testTechnicianWithoutTreatmentIdMayEnterFieldApis(): void
    {
        $this->assertTrue(ApAccess::can('u-tech1', 'treatment.record'));
    }

    public function testInstallCreatesRolesPermissionsAndMenuOnceOnly(): void
    {
        ApAccess::install($this->db, 1);
        $this->assertSame(2, (int)$this->db->fetchOne("SELECT COUNT(*) AS n FROM nu_roles WHERE role_code IN ('ap_admin','ap_technician')")['n']);
        $this->assertSame(2, (int)$this->db->fetchOne("SELECT COUNT(*) AS n FROM nu_permissions WHERE perm_code IN ('allpest.admin','allpest.field')")['n']);
        $perms = fn (string $role) => array_column($this->db->fetchAll('SELECT p.perm_code FROM nu_permissions p JOIN nu_role_permissions rp ON rp.rp_perm_id = p.perm_id JOIN nu_roles r ON r.role_id = rp.rp_role_id WHERE r.role_code = ? ORDER BY 1', [$role]), 'perm_code');
        $this->assertSame(['allpest.admin', 'allpest.field'], $perms('ap_admin'));
        $this->assertSame(['allpest.field'], $perms('ap_technician'));
        $this->assertSame(1, (int)$this->db->fetchOne("SELECT COUNT(*) AS n FROM nu_menus WHERE menu_target = 'allpest'")['n']);
        $m = $this->db->fetchOne("SELECT * FROM nu_menus WHERE menu_target = 'allpest'");
        $this->assertStringContainsString('ap_technician', (string)$m['menu_role_access']);
    }

    /** @return string[] */
    private function endpoints(): array
    {
        return array_values(array_filter(glob(dirname(__DIR__, 3) . '/api/allpest/*.php') ?: [], fn ($f) => basename($f) !== 'request_public.php'));
    }

    public function testEveryNonPublicEndpointSitsBehindLoginAndTheAccessCheck(): void
    {
        $this->assertGreaterThanOrEqual(10, count($this->endpoints()));
        foreach ($this->endpoints() as $f) {
            $src = (string)file_get_contents($f);
            $this->assertStringContainsString('core/module_bootstrap.php', $src, basename($f) . ' needs login');
            $this->assertStringContainsString('ApAccess::', $src, basename($f) . ' needs ApAccess');
            $this->assertTrue(strpos($src, 'module_bootstrap.php') < strpos($src, 'ApAccess::'), basename($f) . ' order');
        }
    }

    public function testStateChangingCallsWithoutTheCsrfTokenAreRefused(): void
    {
        $session = ['nu_user_id' => 'u-tech1', 'nu_csrf' => 'tok'];
        foreach ($this->endpoints() as $f) {
            $server = ['REQUEST_METHOD' => 'POST', 'SCRIPT_NAME' => '/api/allpest/' . basename($f), 'HTTP_HOST' => 'ap.test'];
            $r = NuSecurityGate::check($server, [], [], $session, []);
            $this->assertSame(403, $r['status'] ?? 0, basename($f) . ' without token');
            $this->assertNull(NuSecurityGate::check($server + ['HTTP_X_CSRF_TOKEN' => 'tok'], [], [], $session, []), basename($f) . ' with token');
        }
        $cfg = (string)file_get_contents(dirname(__DIR__, 3) . '/config.php');
        $this->assertStringNotContainsString('allpest', strtolower((string)preg_replace('/\/\/.*$/m', '', explode('csrfExemptScripts', $cfg)[1] ?? '')));
    }
}
