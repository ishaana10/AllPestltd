/* Shrinks a photo in the browser before upload: long edge 1600 px, JPEG quality 0.75, EXIF orientation applied by the browser. */
window.ApCompress = (function () {
  'use strict';
  var MAX = 1600, QUALITY = 0.75;
  function image(file) {
    return new Promise(function (resolve, reject) {
      if (!file || !/^image\//.test(file.type || '')) { return reject(new Error('Choose a photo.')); }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, MAX / Math.max(w, h));
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(w * k)); c.height = Math.max(1, Math.round(h * k));
        var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { b ? resolve(b) : reject(new Error('Could not shrink the photo.')); }, 'image/jpeg', QUALITY);
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('That file is not a readable photo.')); };
      img.src = url;
    });
  }
  function toDataUrl(blob) {
    return new Promise(function (res, rej) { var r = new FileReader(); r.onload = function () { res(r.result); }; r.onerror = rej; r.readAsDataURL(blob); });
  }
  // Decoded by hand: the studio's security policy does not allow fetch() of data: URLs.
  function fromDataUrl(d) {
    return new Promise(function (resolve, reject) {
      try {
        var m = /^data:([^;,]*)(;base64)?,(.*)$/.exec(d);
        if (!m) { throw new Error('Bad photo data.'); }
        var raw = m[2] ? atob(m[3]) : decodeURIComponent(m[3]), bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { bytes[i] = raw.charCodeAt(i); }
        resolve(new Blob([bytes], { type: m[1] || 'application/octet-stream' }));
      } catch (e) { reject(e); }
    });
  }
  return { image: image, toDataUrl: toDataUrl, fromDataUrl: fromDataUrl, MAX: MAX, QUALITY: QUALITY };
})();
