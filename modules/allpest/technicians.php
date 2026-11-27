<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('Technicians and officers', true);
?>
<div class="ap-card"><h2>Technicians</h2><div id="list"></div><h2>Add technician</h2><div id="form"></div>
<p class="ap-muted">Link the sign-in account (user id) so the technician can open their own treatments.</p></div>
<div class="ap-card"><h2>Supervising officers</h2><div id="off"></div><div id="oform"></div></div>
<script>
function load(){ApUi.get('technicians.php').then(function(r){ApUi.table(document.getElementById('list'),r,[{label:'Name',key:'full_name'},{label:'Registration',key:'registration_no'},{label:'Expires',key:'registration_expiry'},{label:'Status',key:'status'}]);},ApUi.fail);
ApUi.get('technicians.php',{list:'officers'}).then(function(r){ApUi.table(document.getElementById('off'),r,[{label:'Name',key:'full_name'},{label:'Designation',key:'designation'}]);},ApUi.fail);}
ApUi.form(document.getElementById('form'),[{name:'full_name',label:'Full name',required:true},{name:'registration_no',label:'Registration no'},{name:'registration_expiry',label:'Registration expiry',type:'date'},{name:'accreditation_no',label:'Accreditation no'},{name:'user_id',label:'Sign-in user id'}],'Add technician',function(v){v.action='create_technician';return ApUi.post('technicians.php',v).then(function(){ApUi.toast('Added');load();});});
ApUi.form(document.getElementById('oform'),[{name:'full_name',label:'Officer name',required:true},{name:'designation',label:'Designation'}],'Add officer',function(v){v.action='add_officer';return ApUi.post('technicians.php',v).then(function(){ApUi.toast('Added');load();});});
load();
</script>
<?php ap_page_end();
