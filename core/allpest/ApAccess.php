<?php
declare(strict_types=1);

require_once __DIR__ . '/ApDb.php';

/**
 * Who may do what in All Pest. Admins (globeadmin, ap_admin, or any role holding allpest.admin) may do everything.
 * A technician (role ap_technician, or any role holding allpest.field, with an active technician profile) acts only on treatments assigned to them.
 * Actions: admin.<area>, treatment.view, treatment.record, treatment.sign.
 */
final class ApAccess
{
    public const ROLE_ADMIN = 'ap_admin';
    public const ROLE_TECH = 'ap_technician';
    private const TREATMENT_ACTIONS = ['treatment.view', 'treatment.record', 'treatment.sign'];

    public static function can(string $userId, string $action, ?string $treatmentId = null): bool
    {
        if ($userId === '' || $userId === 'guest_tester') { return false; }
        $isAdminAction = str_starts_with($action, 'admin.') && strlen($action) > 6;
        if (!$isAdminAction && !in_array($action, self::TREATMENT_ACTIONS, true)) { return false; }

        $db = ApDb::get();
        $u = $db->fetchOne('SELECT usr_role FROM nu_users WHERE usr_id = ?', [$userId]);
        if (!$u) { return false; }
        $role = (string)$u['usr_role'];
        if (self::isAdminRole($role)) { return true; }
        if ($isAdminAction) { return false; }
        if ($role !== self::ROLE_TECH && !self::rolePerm($role, 'allpest.field')) { return false; }

        $tech = $db->fetchOne("SELECT id FROM ap_technicians WHERE user_id = ? AND status = 'active'", [$userId]);
        if (!$tech) { return false; }
        if ($treatmentId === null) { return true; }
        return (bool)$db->fetchOne('SELECT id FROM ap_treatments WHERE id = ? AND technician_id = ?', [$treatmentId, $tech['id']]);
    }

    /** Endpoint helper: sends 403 JSON and stops unless the signed-in user may do $action. */
    public static function guard(string $action, ?string $treatmentId = null): void
    {
        if (self::can((string)($_SESSION['nu_user_id'] ?? ''), $action, $treatmentId)) { return; }
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    private static function isAdminRole(string $role): bool
    {
        return $role === 'globeadmin' || $role === self::ROLE_ADMIN || self::rolePerm($role, 'allpest.admin');
    }

    private static function rolePerm(string $role, string $perm): bool
    {
        return (bool)ApDb::get()->fetchOne(
            'SELECT 1 AS x FROM nu_permissions p JOIN nu_role_permissions rp ON rp.rp_perm_id = p.perm_id JOIN nu_roles r ON r.role_id = rp.rp_role_id WHERE r.role_code = ? AND p.perm_code = ?',
            [$role, $perm]
        );
    }

    /** Create the two roles, their permissions and the sidebar entry. Safe to run repeatedly. */
    public static function install(object $db, int $projectId = 1): void
    {
        $perm = [];
        foreach (['allpest.admin' => 'All Pest administration', 'allpest.field' => 'All Pest field work'] as $code => $name) {
            $row = $db->fetchOne('SELECT perm_id FROM nu_permissions WHERE perm_code = ?', [$code]);
            $perm[$code] = $row ? (int)$row['perm_id'] : (int)$db->insert('nu_permissions', ['perm_code' => $code, 'perm_name' => $name, 'perm_category' => 'allpest', 'perm_description' => $name]);
            if (!$row && !$perm[$code]) { $perm[$code] = (int)$db->fetchOne('SELECT perm_id FROM nu_permissions WHERE perm_code = ?', [$code])['perm_id']; }
        }
        $roles = [self::ROLE_ADMIN => ['All Pest administrator', ['allpest.admin', 'allpest.field']], self::ROLE_TECH => ['All Pest technician', ['allpest.field']]];
        foreach ($roles as $code => [$name, $perms]) {
            $row = $db->fetchOne('SELECT role_id FROM nu_roles WHERE role_code = ?', [$code]);
            $rid = $row ? (int)$row['role_id'] : (int)$db->insert('nu_roles', ['role_code' => $code, 'role_name' => $name, 'role_description' => $name]);
            if (!$rid) { $rid = (int)$db->fetchOne('SELECT role_id FROM nu_roles WHERE role_code = ?', [$code])['role_id']; }
            foreach ($perms as $p) {
                if (!$db->fetchOne('SELECT rp_id FROM nu_role_permissions WHERE rp_role_id = ? AND rp_perm_id = ?', [$rid, $perm[$p]])) {
                    $db->insert('nu_role_permissions', ['rp_role_id' => $rid, 'rp_perm_id' => $perm[$p]]);
                }
            }
        }
        if (!$db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'allpest' AND project_id = ?", [$projectId])) {
            $db->insert('nu_menus', [
                'menu_label' => 'All Pest', 'menu_type' => 'module', 'menu_target' => 'allpest', 'menu_parent_id' => 0, 'menu_order' => 20,
                'menu_roles' => 'globeadmin,ap_admin,ap_technician', 'menu_role_access' => '["globeadmin","ap_admin","ap_technician"]', 'menu_active' => 1, 'menu_icon' => 'clipboard',
                'menu_open_mode' => 'inline|browse', 'menu_browse_mode' => 'inline', 'menu_preview_mode' => 'inline', 'menu_default_view' => 'browse', 'project_id' => $projectId,
            ]);
        }
    }
}
