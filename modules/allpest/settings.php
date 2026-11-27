<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('Settings', true);
?>
<div class="ap-card"><div id="form"></div></div>
<div class="ap-card"><h2>Due soon</h2><div id="due"></div></div>
<div class="ap-card"><button class="ap-btn alt" id="install">Create All Pest roles and menu</button>
<p class="ap-muted">Creates the ap_admin and ap_technician roles and the sidebar entry. Safe to run again.</p></div>
<script>
var KEYS=[['company_name','Company name'],['company_address','Company address'],['treatment_provider_id','Treatment provider ID'],['staff_email','Staff email for reminders'],
['invoice.mode','Invoice release (auto or reviewed)'],['erpx.base_url','ERPX address (https://...)'],['erpx.api_key','ERPX API key'],['erpx.product.general','ERPX product code, general'],
['erpx.product.perishable','ERPX product code, perishable'],['erpx.tax_rate','Tax rate % (set Fiji VAT)'],['certificate_admin_verify','Admin must verify certificates (1 or 0)'],['stock.low_threshold_g','Low stock warning (g)']];
function load(){ApUi.get('settings.php').then(function(d){
  var box=document.getElementById('form');box.innerHTML=KEYS.map(function(k){return '<label class="ap-field"><span>'+ApUi.esc(k[1])+'</span><input data-k="'+k[0]+'" value="'+ApUi.esc(d.settings[k[0]])+'"></label>';}).join('')+'<button class="ap-btn" id="save">Save</button>';
  document.getElementById('save').onclick=function(){var ins=box.querySelectorAll('input');var p=Promise.resolve();ins.forEach(function(i){p=p.then(function(){return ApUi.post('settings.php',{key:i.dataset.k,value:i.value});});});p.then(function(){ApUi.toast('Saved');load();},ApUi.fail);};
  ApUi.table(document.getElementById('due'),d.reminders,[{label:'What',key:'kind'},{label:'Item',key:'subject'},{label:'Due',key:'due'}]);
},ApUi.fail);}
document.getElementById('install').onclick=function(){ApUi.post('settings.php',{action:'install'}).then(function(){ApUi.toast('Done');},ApUi.fail);};
load();
</script>
<?php ap_page_end();
