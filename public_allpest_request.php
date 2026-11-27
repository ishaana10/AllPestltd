<?php
declare(strict_types=1);
/** Public fumigation request form (no sign-in). Submits to api/allpest/request_public.php. */
$nonce = base64_encode(random_bytes(16));
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; base-uri 'none'; img-src data:");
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Request a fumigation - All Pest</title>
<style nonce="<?= $nonce ?>">
body{font:16px/1.45 system-ui,sans-serif;margin:0;background:#f4f6f4;color:#1c2a22}
main{max-width:760px;margin:0 auto;padding:16px}
h1{font-size:1.4rem;margin:.4rem 0 1rem}
fieldset{border:1px solid #cdd8d0;border-radius:8px;background:#fff;margin:0 0 16px;padding:12px 14px}
legend{font-weight:600;padding:0 6px}
label{display:block;margin:8px 0 2px;font-size:.9rem}
input,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #9fb0a5;border-radius:6px;font:inherit}
.row{border:1px dashed #9fb0a5;border-radius:6px;padding:8px;margin:8px 0}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 10px}
@media(max-width:560px){.grid{grid-template-columns:1fr}}
button{padding:12px 18px;border:0;border-radius:6px;background:#1f6f43;color:#fff;font:inherit;cursor:pointer}
button.secondary{background:#eef2ee;color:#1c2a22;border:1px solid #9fb0a5}
.hp{position:absolute;left:-9999px;height:0;overflow:hidden}
#msg{margin:12px 0;padding:10px;border-radius:6px;display:none}
#msg.err{display:block;background:#fde8e8;color:#7a1b1b}
#msg.ok{display:block;background:#e3f5e8;color:#14532d}
</style></head>
<body><main>
<h1>Request a fumigation</h1>
<p>Tell us about your containers and where they are. We will confirm the schedule by email.</p>
<form id="f" novalidate>
 <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
 <fieldset><legend>Your details</legend>
  <label for="customer_name">Name or company *</label><input id="customer_name" name="customer_name" required autocomplete="organization">
  <div class="grid">
   <div><label for="customer_email">Email *</label><input id="customer_email" name="customer_email" type="email" required autocomplete="email"></div>
   <div><label for="customer_phone">Phone</label><input id="customer_phone" name="customer_phone" type="tel" autocomplete="tel"></div>
   <div><label for="customer_tin">TIN (optional)</label><input id="customer_tin" name="customer_tin"></div>
   <div><label for="requested_date">Preferred date</label><input id="requested_date" name="requested_date" type="date"></div>
  </div>
  <label for="customer_address">Billing address</label><textarea id="customer_address" name="customer_address" rows="2"></textarea>
 </fieldset>
 <fieldset><legend>Where will the fumigation take place?</legend>
  <label for="site_address">Site address *</label><textarea id="site_address" name="site_address" rows="2" required></textarea>
 </fieldset>
 <fieldset><legend>Containers</legend>
  <div id="rows"></div>
  <button type="button" class="secondary" id="add">+ Add container</button>
 </fieldset>
 <div id="msg" role="status"></div>
 <button type="submit" id="go">Send request</button>
</form>
</main>
<script nonce="<?= $nonce ?>">
(function(){
 var MAX=50, rows=document.getElementById('rows'), msg=document.getElementById('msg'), f=document.getElementById('f'), go=document.getElementById('go');
 var token=(window.crypto&&crypto.randomUUID)?crypto.randomUUID():String(Date.now())+Math.random().toString(16).slice(2);
 function show(t,c){msg.textContent=t;msg.className=c;}
 function addRow(){
  if(rows.children.length>=MAX){show('At most '+MAX+' containers per request.','err');return;}
  var d=document.createElement('div');d.className='row';
  d.innerHTML='<div class="grid">'+
   '<div><label>Container no.</label><input data-k="container_no" maxlength="30"></div>'+
   '<div><label>Seal no.</label><input data-k="seal_no" maxlength="40"></div>'+
   '<div><label>Commodity</label><input data-k="commodity" maxlength="200"></div>'+
   '<div><label>Quantity</label><input data-k="quantity" maxlength="80"></div>'+
   '<div><label>Origin</label><input data-k="origin" maxlength="100"></div>'+
   '<div><label>Destination</label><input data-k="destination" maxlength="100"></div></div>'+
   '<label>Bill of lading (photo or PDF)</label><input type="file" data-k="bol" accept="image/*,application/pdf">'+
   '<p><button type="button" class="secondary" data-rm="1">Remove</button></p>';
  d.querySelector('[data-rm]').addEventListener('click',function(){rows.removeChild(d);});
  rows.appendChild(d);
 }
 // Shrink photos in the browser before upload (the server compresses again and strips location data).
 function shrink(file){
  return new Promise(function(res){
   if(!/^image\//.test(file.type)){res(file);return;}
   var img=new Image(),u=URL.createObjectURL(file);
   img.onload=function(){
    var s=Math.min(1,1600/Math.max(img.width,img.height)),c=document.createElement('canvas');
    c.width=Math.round(img.width*s);c.height=Math.round(img.height*s);
    var x=c.getContext('2d');x.fillStyle='#fff';x.fillRect(0,0,c.width,c.height);x.drawImage(img,0,0,c.width,c.height);
    URL.revokeObjectURL(u);
    c.toBlob(function(b){res(b?new File([b],'bol.jpg',{type:'image/jpeg'}):file);},'image/jpeg',0.75);
   };
   img.onerror=function(){URL.revokeObjectURL(u);res(file);};
   img.src=u;
  });
 }
 document.getElementById('add').addEventListener('click',addRow);
 addRow();
 f.addEventListener('submit',function(e){
  e.preventDefault();
  var fd=new FormData();
  ['website','customer_name','customer_email','customer_phone','customer_tin','requested_date','customer_address','site_address'].forEach(function(n){fd.append(n,f.elements[n].value);});
  fd.append('client_token',token);
  var list=[].slice.call(rows.children),jobs=[],used=0;
  if(!f.elements.customer_name.value.trim()||!f.elements.customer_email.value.trim()||!f.elements.site_address.value.trim()){show('Please fill in your name, email and the site address.','err');return;}
  list.forEach(function(r,i){
   ['container_no','seal_no','commodity','quantity','origin','destination'].forEach(function(k){fd.append('consignments['+i+']['+k+']',r.querySelector('[data-k='+k+']').value);});
   var fi=r.querySelector('[data-k=bol]').files[0];
   if(fi){used++;jobs.push(shrink(fi).then(function(b){fd.append('bol_'+i,b,b.name||'bol');}));}
  });
  if(used>10){show('You can attach at most 10 bills of lading. Upload one combined file for the rest.','err');return;}
  go.disabled=true;show('Sending...','ok');
  Promise.all(jobs).then(function(){return fetch('api/allpest/request_public.php',{method:'POST',body:fd});})
   .then(function(r){return r.json().catch(function(){return{success:false,message:'Unexpected reply.'};});})
   .then(function(j){
    if(j.success){f.style.display='none';show('Thank you. Your request reference is '+j.reference+'. We emailed you a confirmation.','ok');}
    else{go.disabled=false;show(j.message||j.error||'Could not send your request. If you are signed in to the staff system, open this page in a private window.','err');}
   }).catch(function(){go.disabled=false;show('Network problem. Please try again.','err');});
 });
})();
</script></body></html>
