<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('Methyl bromide stock', true);
?>
<div class="ap-card"><div id="list"></div></div>
<div class="ap-card"><h2>New lot</h2><div id="lot"></div><h2>Receive gas</h2><div id="rec"></div><h2>Adjust (needs a reason)</h2><div id="adj"></div></div>
<div class="ap-card"><h2>Usage this month</h2><div id="usage"></div></div>
<script>
function opts(r){return r.map(function(l){return {value:l.id,label:l.lot_ref};});}
function load(){ApUi.get('stock.php').then(function(r){ApUi.table(document.getElementById('list'),r,[{label:'Lot',key:'lot_ref'},{label:'Description',key:'description'},{label:'Balance (g)',key:'balance'}]);
 ['rec','adj'].forEach(function(id){var s=document.querySelector('#'+id+' select');if(s){s.innerHTML=opts(r).map(function(o){return '<option value="'+ApUi.esc(o.value)+'">'+ApUi.esc(o.label)+'</option>';}).join('');}});},ApUi.fail);
 ApUi.get('stock.php',{report:'usage'}).then(function(u){document.getElementById('usage').textContent=u.total_g+' g used across '+u.lots.length+' lot(s)';},ApUi.fail);}
var lots=[{value:'',label:'Loading...'}];
ApUi.form(document.getElementById('lot'),[{name:'lot_ref',label:'Lot reference',required:true},{name:'description',label:'Description'}],'Create lot',function(v){v.action='create_lot';return ApUi.post('stock.php',v).then(function(){ApUi.toast('Created');load();});});
ApUi.form(document.getElementById('rec'),[{name:'lot_id',label:'Lot',type:'select',options:lots},{name:'grams',label:'Grams received',type:'number',step:'any',required:true}],'Receive',function(v){v.action='receive';return ApUi.post('stock.php',v).then(function(){ApUi.toast('Recorded');load();});});
ApUi.form(document.getElementById('adj'),[{name:'lot_id',label:'Lot',type:'select',options:lots},{name:'grams',label:'Grams (negative to remove)',type:'number',step:'any',required:true},{name:'reason',label:'Reason',required:true}],'Adjust',function(v){v.action='adjust';return ApUi.post('stock.php',v).then(function(){ApUi.toast('Recorded');load();});});
load();
</script>
<?php ap_page_end();
