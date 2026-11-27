<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('Record of fumigation');
?>
<div id="pick" class="ap-card" hidden><h2>Choose a treatment</h2><div id="mine"></div></div>
<div id="rof" hidden>
  <div class="ap-card"><div id="head"></div><p id="queue" class="ap-muted"></p><div id="locked" class="ap-alert" hidden></div></div>
  <div class="ap-card" id="s_pre" hidden><h2>1. Pre-treatment check</h2><div id="pre"></div></div>
  <div class="ap-card" id="s_dose" hidden><h2>2. Apply the gas</h2><div id="dose"></div></div>
  <div class="ap-card" id="s_read" hidden><h2>3. Gas readings</h2><div id="read"></div></div>
  <div class="ap-card" id="s_end" hidden><h2>4. Top-up, ventilation and TLV</h2><div id="end"></div></div>
</div>
<script src="../../assets/js/allpest/compress.js"></script><script src="../../assets/js/allpest/queue.js"></script><script src="../../assets/js/allpest/rof.js"></script>
<?php ap_page_end();
