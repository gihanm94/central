/* Machine Checklist helpers: QR codes ([data-qr]), copy link ([data-copy]). */
(function () {
    'use strict';
    function renderQr(el) {
        if (!window.qrcode || el._done) return;
        var q = window.qrcode(0, 'M'); q.addData(el.dataset.qr); q.make();
        var n = q.getModuleCount(), cell = 4, size = (n + 2) * cell, d = '';
        for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (q.isDark(r, c)) d += 'M' + ((c + 1) * cell) + ',' + ((r + 1) * cell) + 'h' + cell + 'v' + cell + 'h-' + cell + 'z';
        el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="100%" height="100%" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
        el._done = true;
    }
    function boot() { Array.prototype.forEach.call(document.querySelectorAll('[data-qr]'), renderQr); }
    boot(); document.addEventListener('DOMContentLoaded', boot);
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-copy]'); if (!b) return;
        var t = b.dataset.copy;
        (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).catch(function () { var i = document.createElement('input'); i.value = t; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); } catch (x) {} i.remove(); })
            .then(function () { if (window.toast) window.toast(b.dataset.done || 'Copied', 'success'); });
    });

    /* checklist form: the "what is wrong" box opens on NG, a counter shows how many are answered */
    var form = document.querySelector('form[data-check]');
    if (form) {
        var total = form.querySelectorAll('[data-q]').length, label = form.querySelector('[data-progress]');
        var count = function () {
            var done = 0;
            Array.prototype.forEach.call(form.querySelectorAll('[data-q]'), function (q) {
                var r = q.querySelector('[data-ans]:checked'), t = q.querySelector('[data-ans-text]');
                var rem = q.querySelector('[data-remark]');
                if (rem) rem.hidden = !(r && r.value === 'NG');
                if (r || (t && t.value.trim() !== '')) done++;
            });
            if (label) label.textContent = done + ' / ' + total;
        };
        form.addEventListener('change', count); form.addEventListener('input', count); count();
    }
})();
