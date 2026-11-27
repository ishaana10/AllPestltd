<?php
declare(strict_types=1);
/**
 * Request status board, loaded inline in the StudioX shell (sidebar: All Pest > Request Board). One column per status. Read-only:
 * status changes stay in the engine (confirm, schedule, treatment progress). Clicking a card opens the request in the studio form.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApDb.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApSchema.php';

$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
if (!ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.requests')) {
    http_response_code(403);
    exit('<div style="padding:24px">Access denied.</div>');
}
$cols = ['new' => 'New', 'confirmed' => 'Confirmed', 'scheduled' => 'Scheduled', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$rows = $db->fetchAll('SELECT id, reference, customer_name, status, requested_date FROM ap_requests ORDER BY created_at DESC LIMIT 500');
$by = array_fill_keys(array_keys($cols), []);
foreach ($rows as $r) { $by[isset($by[$r['status']]) ? $r['status'] : 'new'][] = $r; }
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="assets/css/allpest-studio.css">
<div class="ap-scope">
  <h1>Request board</h1>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start">
  <?php foreach ($cols as $code => $label): ?>
    <div class="ap-card"><h2><?php echo $e($label); ?> <span class="ap-muted">(<?php echo count($by[$code]); ?>)</span></h2>
      <?php foreach ($by[$code] as $r): ?>
        <p><a href="#" class="ap-open" data-id="<?php echo $e($r['id']); ?>"><strong><?php echo $e($r['reference']); ?></strong></a><br>
        <?php echo $e($r['customer_name']); ?><?php echo $r['requested_date'] ? '<br><span class="ap-muted">' . $e($r['requested_date']) . '</span>' : ''; ?></p>
      <?php endforeach; ?>
      <?php if (!$by[$code]): ?><p class="ap-muted">Nothing here.</p><?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<script>
document.querySelectorAll('.ap-open').forEach(function (a) {
  a.addEventListener('click', function (ev) { ev.preventDefault(); NuApp.editRecord('ap_requests', a.dataset.id, 'Fumigation Requests', 'inline'); });
});
</script>
