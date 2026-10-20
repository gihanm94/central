/*
 | Management → Member signatures: the add / change dialog (#sig-dialog). "Draw" is a signature pad (mouse, pen, finger;
 | smooth strokes, black or blue ink); "Upload image" sends a picture. Either way the server cleans and stores it.
 */
(function () {
    'use strict';
    var dlg = document.getElementById('sig-dialog'); if (!dlg) return;
    var form = dlg.querySelector('[data-sig-form]'), canvas = dlg.querySelector('[data-sig-pad]'), ctx = canvas.getContext('2d');
    var mode = 'draw', ink = '#111827', drawn = false, pts = [], down = false;

    function size() {
        var r = canvas.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(r.width * dpr); canvas.height = Math.round(r.height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    }
    function clear() { ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; dlg.querySelector('[data-sig-hint]').hidden = false; }
    function setMode(m) {
        mode = m;
        dlg.querySelectorAll('[data-sig-mode]').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.sigMode === m ? 'true' : 'false'); });
        dlg.querySelectorAll('[data-sig-panel]').forEach(function (p) { p.hidden = p.dataset.sigPanel !== m; });
        if (m === 'draw') size();
    }

    document.addEventListener('click', function (e) {
        var o = e.target.closest('[data-sig-open]'); if (!o) return;
        form.action = dlg.dataset.base + '/' + o.dataset.sigOpen;
        dlg.querySelector('[data-sig-who]').textContent = o.dataset.sigName;
        form.reset(); form.drawn.value = '';
        var pv = dlg.querySelector('[data-sig-preview]'); pv.classList.add('hidden'); dlg.querySelector('[data-sig-pick]').hidden = false;
        dlg.showModal(); setMode('draw'); clear();
    });
    dlg.querySelectorAll('[data-sig-mode]').forEach(function (b) { b.addEventListener('click', function () { setMode(b.dataset.sigMode); }); });
    dlg.querySelector('[data-sig-clear]').addEventListener('click', clear);
    dlg.querySelectorAll('[data-sig-color]').forEach(function (b) { b.addEventListener('click', function () {
        ink = b.dataset.sigColor;
        dlg.querySelectorAll('[data-sig-color]').forEach(function (x) { x.classList.toggle('ring-signal-600', x === b); x.classList.toggle('ring-transparent', x !== b); });
    }); });
    dlg.querySelector('[data-sig-file]').addEventListener('change', function () {
        var f = this.files[0], pv = dlg.querySelector('[data-sig-preview]'); if (!f) return;
        pv.src = URL.createObjectURL(f); pv.classList.remove('hidden'); dlg.querySelector('[data-sig-pick]').hidden = true;
    });

    // pad: quadratic curves through the midpoints, line width follows speed a little
    function pos(e) { var r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top, t: Date.now() }; }
    canvas.addEventListener('pointerdown', function (e) { e.preventDefault(); canvas.setPointerCapture(e.pointerId); down = true; pts = [pos(e)]; dlg.querySelector('[data-sig-hint]').hidden = true;
        ctx.fillStyle = ink; ctx.beginPath(); ctx.arc(pts[0].x, pts[0].y, 1.3, 0, Math.PI * 2); ctx.fill(); drawn = true; });
    canvas.addEventListener('pointermove', function (e) {
        if (!down) return;
        var p = pos(e), a = pts[pts.length - 1]; pts.push(p);
        if (pts.length < 3) return;
        var b = pts[pts.length - 2], c = pts[pts.length - 3];
        var v = Math.hypot(p.x - a.x, p.y - a.y) / Math.max(1, p.t - a.t);
        ctx.strokeStyle = ink; ctx.lineWidth = Math.max(1.4, Math.min(3.4, 3.4 - v * 0.9));
        ctx.beginPath(); ctx.moveTo((c.x + b.x) / 2, (c.y + b.y) / 2); ctx.quadraticCurveTo(b.x, b.y, (b.x + p.x) / 2, (b.y + p.y) / 2); ctx.stroke();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { canvas.addEventListener(ev, function () { down = false; }); });
    window.addEventListener('resize', function () { if (dlg.open && mode === 'draw' && !drawn) size(); });

    form.addEventListener('submit', function (e) {
        if (mode === 'draw') {
            if (!drawn) { e.preventDefault(); (window.toast || alert)(dlg.dataset.lEmpty, 'error'); return; }
            form.drawn.value = canvas.toDataURL('image/png');
            form.querySelector('[data-sig-file]').value = '';
        } else {
            form.drawn.value = '';
            if (!form.querySelector('[data-sig-file]').files.length) { e.preventDefault(); (window.toast || alert)(dlg.dataset.lPick, 'error'); }
        }
    });
})();
