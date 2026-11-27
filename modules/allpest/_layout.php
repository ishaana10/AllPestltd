<?php
declare(strict_types=1);
/** Shared page frame for the All Pest screens (tablet-first: large touch targets, one column). Include after module_bootstrap. */
require_once dirname(__DIR__, 2) . '/core/allpest/ApDb.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApSchema.php';

function ap_page_start(string $title, bool $adminOnly = false): void
{
    global $auth;
    $db = NuDatabase::getInstance();
    ApSchema::ensure($db);
    ApDb::set($db);
    $uid = (string)($_SESSION['nu_user_id'] ?? '');
    $isAdmin = ApAccess::can($uid, 'admin.pages');
    if (!$isAdmin && ($adminOnly || !ApAccess::can($uid, 'treatment.record'))) {
        http_response_code(403);
        exit('Access denied');
    }
    $t = htmlspecialchars($title, ENT_QUOTES);
    $csrf = json_encode($auth->getCsrfToken());
    $links = ['index.php' => 'Home', 'treatment.php' => 'Treatments'];
    if ($isAdmin) { $links += ['requests.php' => 'Requests', 'technicians.php' => 'Technicians', 'equipment.php' => 'Equipment', 'schedules.php' => 'Schedules', 'stock.php' => 'Stock', 'settings.php' => 'Settings']; }
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>$t - All Pest</title>"
        . '<link rel="stylesheet" href="../../assets/css/allpest.css"></head><body><nav class="ap-nav">';
    foreach ($links as $href => $label) { echo '<a href="' . $href . '">' . $label . '</a>'; }
    echo "</nav><main class=\"ap-main\"><h1>$t</h1><div id=\"apToast\" class=\"ap-toast\" role=\"status\"></div>"
        . "<script>window.nuCsrfToken=$csrf;</script><script src=\"../../assets/js/nu-csrf.js\"></script>"
        . '<script src="../../assets/js/allpest/ui.js"></script>';
}

function ap_page_end(array $scripts = []): void
{
    foreach ($scripts as $s) { echo '<script src="../../assets/js/allpest/' . htmlspecialchars($s, ENT_QUOTES) . '"></script>'; }
    echo '</main></body></html>';
}
