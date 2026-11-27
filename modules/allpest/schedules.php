<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('Treatment schedules', true);
?>
<div class="ap-card"><div id="list"></div></div>
<div class="ap-card"><h2>Add schedule</h2><div id="form"></div></div>
<script>
function load(){ApUi.get('schedules.php').then(function(r){ApUi.table(document.getElementById('list'),r,[{label:'Name',key:'name'},{label:'Type',key:'treatment_type'},{label:'Dose g/m3',key:'dose_rate'},{label:'Hours',key:'exposure_hours'},{label:'Min temp C',key:'min_temp_c'},{label:'Class',key:'retention_class'}]);},ApUi.fail);}
var yn=[{value:'0',label:'No'},{value:'1',label:'Yes'}];
ApUi.form(document.getElementById('form'),[{name:'name',label:'Name',required:true},{name:'treatment_type',label:'Type',type:'select',options:[{value:'general',label:'General'},{value:'perishable',label:'Perishable'}]},{name:'dose_rate',label:'Dose rate g/m3',type:'number',step:'any',required:true},{name:'exposure_hours',label:'Exposure hours',type:'number',step:'any',required:true},{name:'min_temp_c',label:'Minimum temperature C',type:'number',step:'any',value:'21'},{name:'topups_allowed',label:'Top-ups allowed',type:'select',options:[{value:'1',label:'Yes'},{value:'0',label:'No'}]},{name:'compensation_allowed',label:'Cold compensation allowed',type:'select',options:yn},{name:'retention_class',label:'Retention class',type:'select',options:[{value:'standard',label:'Standard'},{value:'eighty',label:'80 percent'}]}],'Save schedule',function(v){return ApUi.post('schedules.php',v).then(function(){ApUi.toast('Saved');load();});});
load();
</script>
<?php ap_page_end();
