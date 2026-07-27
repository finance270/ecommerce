/* Grafik ringan tanpa pustaka eksternal (container bisa jalan tanpa internet). */
(function () {
  'use strict';

  function fmtShort(n) {
    var a = Math.abs(n);
    if (a >= 1e9) return (n / 1e9).toFixed(1).replace('.', ',') + ' M';
    if (a >= 1e6) return (n / 1e6).toFixed(1).replace('.', ',') + ' jt';
    if (a >= 1e3) return Math.round(n / 1e3) + ' rb';
    return String(Math.round(n));
  }

  function draw(canvas) {
    var series;
    try { series = JSON.parse(canvas.dataset.series || '[]'); } catch (e) { return; }
    if (!series.length) return;

    var type = canvas.dataset.type || 'line';
    var dpr = window.devicePixelRatio || 1;
    var w = canvas.clientWidth || canvas.parentNode.clientWidth || 600;
    var h = canvas.clientHeight || 260;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    var c = canvas.getContext('2d');
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    c.clearRect(0, 0, w, h);

    var padL = 62, padR = 12, padT = 12, padB = 30;
    var iw = w - padL - padR, ih = h - padT - padB;
    var vals = series.map(function (d) { return +d.v || 0; });
    var max = Math.max.apply(null, vals);
    var min = Math.min(0, Math.min.apply(null, vals));
    if (max === min) max = min + 1;

    var y = function (v) { return padT + ih - ((v - min) / (max - min)) * ih; };

    // garis bantu + label sumbu Y
    c.strokeStyle = '#e9eef5'; c.fillStyle = '#8494a8';
    c.font = '11px -apple-system, Segoe UI, Roboto, sans-serif';
    c.textAlign = 'right'; c.lineWidth = 1;
    for (var i = 0; i <= 4; i++) {
      var v = min + (max - min) * i / 4, py = Math.round(y(v)) + 0.5;
      c.beginPath(); c.moveTo(padL, py); c.lineTo(w - padR, py); c.stroke();
      c.fillText(fmtShort(v), padL - 8, py + 3.5);
    }

    var n = series.length;
    var color = canvas.dataset.color || '#1f6feb';

    if (type === 'bar') {
      var bw = Math.max(2, iw / n * 0.68), step = iw / n;
      c.fillStyle = color;
      series.forEach(function (d, i2) {
        var vv = +d.v || 0, top = y(Math.max(vv, 0)), base = y(0);
        var x = padL + step * i2 + (step - bw) / 2;
        c.fillRect(x, Math.min(top, base), bw, Math.max(1, Math.abs(base - top)));
      });
    } else {
      var stepL = n > 1 ? iw / (n - 1) : 0;
      var pts = series.map(function (d, i3) { return [padL + stepL * i3, y(+d.v || 0)]; });

      var grad = c.createLinearGradient(0, padT, 0, padT + ih);
      grad.addColorStop(0, color + '33');
      grad.addColorStop(1, color + '00');
      c.beginPath();
      c.moveTo(pts[0][0], y(Math.max(min, 0)));
      pts.forEach(function (p) { c.lineTo(p[0], p[1]); });
      c.lineTo(pts[n - 1][0], y(Math.max(min, 0)));
      c.closePath(); c.fillStyle = grad; c.fill();

      c.beginPath();
      pts.forEach(function (p, i4) { i4 ? c.lineTo(p[0], p[1]) : c.moveTo(p[0], p[1]); });
      c.strokeStyle = color; c.lineWidth = 2; c.lineJoin = 'round'; c.stroke();

      if (n <= 60) {
        c.fillStyle = color;
        pts.forEach(function (p) { c.beginPath(); c.arc(p[0], p[1], 2.5, 0, 6.284); c.fill(); });
      }
    }

    // label sumbu X (maksimal 8 supaya tidak tumpuk)
    c.fillStyle = '#8494a8'; c.textAlign = 'center';
    var every = Math.max(1, Math.ceil(n / 8));
    series.forEach(function (d, i5) {
      if (i5 % every !== 0 && i5 !== n - 1) return;
      var x = type === 'bar' ? padL + (iw / n) * (i5 + 0.5) : padL + (n > 1 ? iw / (n - 1) : 0) * i5;
      c.fillText(d.l || '', x, h - 10);
    });
  }

  function drawAll() {
    document.querySelectorAll('canvas.chart').forEach(draw);
  }

  document.addEventListener('DOMContentLoaded', function () {
    drawAll();

    var t;
    window.addEventListener('resize', function () {
      clearTimeout(t);
      t = setTimeout(drawAll, 150);
    });

    // area unggah berkas
    var drop = document.getElementById('drop');
    var input = document.getElementById('files');
    var list = document.getElementById('filelist');
    if (!drop || !input) return;

    function render() {
      if (!list) return;
      list.innerHTML = '';
      Array.prototype.forEach.call(input.files, function (f) {
        var d = document.createElement('div');
        d.className = 'fileitem';
        d.innerHTML = '<span class="name"></span><span class="muted"></span>';
        d.firstChild.textContent = f.name;
        d.lastChild.textContent = (f.size / 1048576).toFixed(2).replace('.', ',') + ' MB';
        list.appendChild(d);
      });
    }

    drop.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', render);
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; render(); }
    });

    var form = document.getElementById('uploadform');
    if (form) {
      form.addEventListener('submit', function () {
        var b = document.getElementById('submitbtn');
        if (b) { b.disabled = true; b.textContent = 'Memproses, mohon tunggu...'; }
      });
    }
  });
})();
