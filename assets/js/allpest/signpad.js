/* A signature pad on a canvas. toPng() returns a PNG data URL, or null when nothing was drawn (a blank signature is refused). */
window.ApSignPad = function (canvas) {
  'use strict';
  var ctx = canvas.getContext('2d'), drawing = false, ink = 0, last = null;
  function size() {
    var r = canvas.getBoundingClientRect(), d = window.devicePixelRatio || 1;
    canvas.width = Math.round(r.width * d); canvas.height = Math.round(r.height * d);
    ctx.scale(d, d); ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
    ink = 0;
  }
  function pos(e) { var r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
  canvas.style.touchAction = 'none';
  canvas.addEventListener('pointerdown', function (e) { drawing = true; last = pos(e); canvas.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.moveTo(last.x, last.y); e.preventDefault(); });
  canvas.addEventListener('pointermove', function (e) {
    if (!drawing) return;
    var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke();
    ink += Math.abs(p.x - last.x) + Math.abs(p.y - last.y); last = p; e.preventDefault();
  });
  function end() { drawing = false; }
  canvas.addEventListener('pointerup', end); canvas.addEventListener('pointercancel', end);
  size();
  return {
    clear: function () { ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.restore(); ink = 0; },
    isBlank: function () { return ink < 40; },
    toPng: function () { return ink < 40 ? null : canvas.toDataURL('image/png'); }
  };
};
