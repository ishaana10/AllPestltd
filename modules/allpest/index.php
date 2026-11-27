<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('All Pest');
$isAdmin = ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.pages');
?>
<p id="queue" class="ap-muted"></p>
<div class="ap-card"><h2>My treatments</h2><div id="mine"></div></div>
<?php if ($isAdmin): ?>
<div class="ap-card"><h2>Needs attention</h2><div id="due"></div></div>
<?php endif; ?>
<script>
ApUi.get('treatments.php',{mine:1}).then(function(r){ApUi.table(document.getElementById('mine'),r,[{label:'Reference',render:function(x){return '<a href="treatment.php?id='+ApUi.esc(x.id)+'">'+ApUi.esc(x.reference)+'</a>';}},{label:'Customer',key:'customer_name'},{label:'Type',key:'rof_type'},{label:'Status',render:function(x){return '<span class="ap-badge">'+ApUi.esc(x.status)+'</span>';}},{label:'Start',render:function(x){return ApUi.fiji(x.scheduled_start);}}]);},ApUi.fail);
<?php if ($isAdmin): ?>
ApUi.get('settings.php').then(function(d){ApUi.table(document.getElementById('due'),d.reminders,[{label:'What',key:'kind'},{label:'Item',key:'subject'},{label:'Due',key:'due'}]);},ApUi.fail);
<?php endif; ?>
ApQueue.onChange(function(n,r){document.getElementById('queue').textContent=ApQueue.statusText(n,r);});
</script>
<?php ap_page_end(['compress.js', 'queue.js']);
