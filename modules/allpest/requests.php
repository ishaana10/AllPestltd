<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('Fumigation requests', true);
?>
<div class="ap-card"><div id="list"></div></div>
<div class="ap-card" id="detail" hidden></div>
<script>
function load(){ApUi.get('requests.php').then(function(r){ApUi.table(document.getElementById('list'),r,[{label:'Reference',render:function(x){return '<a href="#" data-id="'+ApUi.esc(x.id)+'" class="open">'+ApUi.esc(x.reference)+'</a>';}},{label:'Customer',key:'customer_name'},{label:'Status',render:function(x){return '<span class="ap-badge">'+ApUi.esc(x.status)+'</span>';}},{label:'Received',render:function(x){return ApUi.fiji(x.created_at);}}]);
 document.querySelectorAll('a.open').forEach(function(a){a.onclick=function(e){e.preventDefault();open(a.dataset.id);};});},ApUi.fail);}
function open(id){
 Promise.all([ApUi.get('requests.php',{id:id}),ApUi.get('technicians.php'),ApUi.get('technicians.php',{list:'officers'}),ApUi.get('schedules.php'),ApUi.get('invoices.php',{request_id:id})]).then(function(a){
  var q=a[0],techs=a[1],offs=a[2],scheds=a[3],inv=a[4],d=document.getElementById('detail');d.hidden=false;
  var o=function(rows,l){return rows.map(function(x){return '<option value="'+ApUi.esc(x.id)+'">'+ApUi.esc(x[l])+'</option>';}).join('');};
  var cons=q.consignments.map(function(c){return '<label style="display:block"><input type="checkbox" class="cc" value="'+ApUi.esc(c.id)+'"'+(c.treatment_id?' disabled':'')+' style="width:auto;min-height:0"> '+ApUi.esc(c.container_no||'(no number)')+' '+ApUi.esc(c.commodity||'')+(c.treatment_id?' - scheduled':'')+'</label>';}).join('');
  d.innerHTML='<h2>'+ApUi.esc(q.reference)+' - '+ApUi.esc(q.customer_name)+'</h2><p>'+ApUi.esc(q.customer_email)+' '+ApUi.esc(q.customer_phone||'')+'<br>'+ApUi.esc(q.site_address||'')+'</p>'+
   '<p>Status: <span class="ap-badge">'+ApUi.esc(q.status)+'</span></p>'+
   (q.status==='new'?'<div class="ap-grid"><label class="ap-field"><span>Site latitude</span><input id="lat" type="number" step="any" value="'+ApUi.esc(q.site_lat||'')+'"></label><label class="ap-field"><span>Site longitude</span><input id="lng" type="number" step="any" value="'+ApUi.esc(q.site_lng||'')+'"></label></div><button class="ap-btn" id="confirm">Confirm request</button>':'')+
   (q.status==='confirmed'||q.status==='scheduled'||q.status==='in_progress'?'<h2>Schedule a treatment</h2>'+cons+
   '<label class="ap-field"><span>Technician</span><select id="tech">'+o(techs,'full_name')+'</select></label><label class="ap-field"><span>Supervising officer</span><select id="off">'+o(offs,'full_name')+'</select></label>'+
   '<label class="ap-field"><span>Schedule</span><select id="sch">'+o(scheds,'name')+'</select></label><label class="ap-field"><span>Start (Fiji time)</span><input id="start" type="datetime-local"></label>'+
   '<label class="ap-field"><span>ROF type</span><select id="rof"><option value="general">General</option><option value="perishable">Perishable</option></select></label>'+
   '<label class="ap-field"><span>Where is the gas applied?</span><select id="enc"><option value="container">Inside the container</option><option value="container_stack">Stack of containers under sheet</option><option value="other">Other enclosure</option></select></label><button class="ap-btn" id="sched">Schedule</button>':'')+
   '<h2>Treatments</h2><div>'+(q.treatments.map(function(t){return '<p><a href="signing.php?id='+ApUi.esc(t.id)+'">'+ApUi.esc(t.rof_type)+' - '+ApUi.esc(t.status)+'</a> '+ApUi.fiji(t.scheduled_start)+' <a href="treatment.php?id='+ApUi.esc(t.id)+'">open ROF</a></p>';}).join('')||'<p class="ap-muted">None yet.</p>')+'</div>'+
   '<h2>Invoice</h2><div id="inv">'+(inv&&inv.state?'<p>'+ApUi.esc(inv.erpx_invoice_no||'not sent yet')+' - <span class="ap-badge">'+ApUi.esc(inv.state)+'</span> '+ApUi.esc(inv.last_error||'')+'</p>'+(inv.state==='draft'?'<button class="ap-btn" id="rel">Release invoice</button>':'')+(inv.state==='pending'?'<button class="ap-btn alt" id="retry">Retry now</button>':''):'<p class="ap-muted">Raised automatically when every container is treated.</p>')+'</div>';
  var b;
  if(b=document.getElementById('confirm'))b.onclick=function(){ApUi.post('requests.php',{action:'confirm',request_id:id,site_lat:document.getElementById('lat').value,site_lng:document.getElementById('lng').value}).then(function(){ApUi.toast('Confirmed');load();open(id);},ApUi.fail);};
  if(b=document.getElementById('sched'))b.onclick=function(){var ids=[].map.call(d.querySelectorAll('.cc:checked'),function(x){return x.value;});if(!ids.length){ApUi.toast('Tick at least one container',true);return;}
    var s=document.getElementById('start').value;if(!s){ApUi.toast('Choose the start time',true);return;}var u=new Date(s+':00+12:00').toISOString().slice(0,19).replace('T',' ');
    ApUi.post('requests.php',{action:'schedule',request_id:id,container_ids:ids,technician_id:document.getElementById('tech').value,officer_id:document.getElementById('off').value,schedule_id:document.getElementById('sch').value,scheduled_start:u,rof_type:document.getElementById('rof').value,enclosure_type:document.getElementById('enc').value,fumigation_type:'ambient',site_lat:q.site_lat||'',site_lng:q.site_lng||''}).then(function(){ApUi.toast('Scheduled');load();open(id);},ApUi.fail);};
  if(b=document.getElementById('rel'))b.onclick=function(){ApUi.post('invoices.php',{action:'release',request_id:id}).then(function(){ApUi.toast('Released');open(id);},ApUi.fail);};
  if(b=document.getElementById('retry'))b.onclick=function(){ApUi.post('invoices.php',{action:'retry',request_id:id}).then(function(){open(id);},ApUi.fail);};
 },ApUi.fail);}
load();
</script>
<?php ap_page_end();
