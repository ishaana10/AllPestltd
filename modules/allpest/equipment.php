<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('Equipment', true);
?>
<div class="ap-card"><div id="list"></div></div>
<div class="ap-card"><h2>Add equipment</h2><div id="form"></div></div>
<div class="ap-card"><h2>Record an audit, repair, replacement or disposal</h2><div id="log"></div></div>
<script>
function load(){ApUi.get('equipment.php',{list:'all'}).then(function(r){window.EQ=r;ApUi.table(document.getElementById('list'),r,[{label:'Kind',key:'kind'},{label:'Name',key:'name'},{label:'Serial (last 4)',render:function(x){return ApUi.esc(String(x.serial_no||'').slice(-4));}},{label:'Status',key:'status'},{label:'Next audit',key:'next_audit_due'},{label:'Expiry',key:'expiry_date'}]);
 var sel=document.getElementById('f_equipment_id');if(sel){sel.innerHTML=r.map(function(x){return '<option value="'+ApUi.esc(x.id)+'">'+ApUi.esc(x.name)+'</option>';}).join('');}},ApUi.fail);}
ApUi.form(document.getElementById('form'),[{name:'kind',label:'Kind (detector, fan, sheet ...)',required:true},{name:'name',label:'Name',required:true},{name:'serial_no',label:'Serial no'},{name:'next_audit_due',label:'Next audit due',type:'date'},{name:'expiry_date',label:'Calibration/expiry date',type:'date'}],'Add',function(v){v.action='create';return ApUi.post('equipment.php',v).then(function(){ApUi.toast('Added');load();});});
ApUi.form(document.getElementById('log'),[{name:'equipment_id',label:'Item',type:'select',options:[{value:'',label:'Loading...'}]},{name:'kind',label:'Event',type:'select',options:['audit','repair','replace','discard'].map(function(x){return {value:x,label:x};})},{name:'note',label:'Note'},{name:'next_audit_due',label:'Next audit due (for an audit)',type:'date'}],'Save event',function(v){v.action='log';return ApUi.post('equipment.php',v).then(function(){ApUi.toast('Saved');load();});});
load();
</script>
<?php ap_page_end();
