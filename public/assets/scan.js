/* QR scanner: camera (BarcodeDetector when the browser has it, jsQR otherwise), photo of a QR code, or the typed code. */
(function () {
    'use strict';
    var root = document.getElementById('scan'); if (!root) return;
    var $ = function (s) { return root.querySelector(s); };
    var video = $('[data-scan-video]'), idle = $('[data-scan-idle]'), frame = $('[data-scan-frame]'), msg = $('[data-scan-msg]');
    var startBtn = $('[data-scan-start]'), stopBtn = $('[data-scan-stop]'), fileIn = $('[data-scan-file]');
    var stream = null, timer = 0, busy = false, detector = null, canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
    try { if ('BarcodeDetector' in window) detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { detector = null; }

    function go(text) {
        if (busy) return; busy = true;
        fetch(root.dataset.lookup + '?text=' + encodeURIComponent(text), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.ok) { stop(); if (navigator.vibrate) navigator.vibrate(60); location.href = d.url; } else { busy = false; say(d.message || root.dataset.msgNomatch); } })
            .catch(function () { busy = false; say('Network error.'); });
    }
    function say(t) { msg.textContent = t; var e = $('[data-code-error]'); e.textContent = t; e.hidden = !t; idle.hidden = !!stream; }

    function tick() {
        if (!stream || !video.videoWidth) { timer = setTimeout(tick, 120); return; }
        var done = function (text) { if (text) go(text); timer = setTimeout(tick, busy ? 400 : 160); };
        if (detector) { detector.detect(video).then(function (c) { done(c[0] && c[0].rawValue); }).catch(function () { done(null); }); return; }
        var w = video.videoWidth, h = video.videoHeight, s = Math.min(1, 640 / Math.max(w, h));
        canvas.width = Math.round(w * s); canvas.height = Math.round(h * s);
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        var d = ctx.getImageData(0, 0, canvas.width, canvas.height), r = window.jsQR ? window.jsQR(d.data, d.width, d.height, { inversionAttempts: 'dontInvert' }) : null;
        done(r && r.data);
    }
    function start() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say(root.dataset.msgNocam); return; }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }).then(function (s) {
            stream = s; video.srcObject = s; video.hidden = false; idle.hidden = true; frame.classList.remove('hidden'); stopBtn.hidden = false;
            video.play().catch(function () {}); clearTimeout(timer); tick();
        }).catch(function () { say(root.dataset.msgNocam); });
    }
    function stop() {
        clearTimeout(timer); if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
        stream = null; video.hidden = true; idle.hidden = false; frame.classList.add('hidden'); stopBtn.hidden = true;
    }
    startBtn.addEventListener('click', start); stopBtn.addEventListener('click', stop);
    window.addEventListener('pagehide', stop);

    fileIn.addEventListener('change', function () {
        var f = fileIn.files && fileIn.files[0]; if (!f) return;
        var img = new Image(); img.onload = function () {
            var s = Math.min(1, 1000 / Math.max(img.width, img.height)); canvas.width = Math.round(img.width * s); canvas.height = Math.round(img.height * s);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            var d = ctx.getImageData(0, 0, canvas.width, canvas.height), r = window.jsQR && window.jsQR(d.data, d.width, d.height);
            URL.revokeObjectURL(img.src); fileIn.value = '';
            if (r && r.data) go(r.data); else say(root.dataset.msgNomatch);
        }; img.src = URL.createObjectURL(f);
    });

    // typed code: Enter opens it, typing shows matches
    var form = $('[data-code-form]'), input = $('[data-code]'), list = $('[data-results]'), t2 = 0;
    form.addEventListener('submit', function (e) { e.preventDefault(); var v = input.value.trim(); if (v) { busy = false; go(v); } });
    input.addEventListener('input', function () {
        clearTimeout(t2); var v = input.value.trim(); $('[data-code-error]').hidden = true;
        if (v.length < 2) { list.innerHTML = ''; return; }
        t2 = setTimeout(function () {
            fetch(root.dataset.find + '?q=' + encodeURIComponent(v), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
                list.innerHTML = ''; (d.rows || []).forEach(function (m) {
                    var li = document.createElement('li'), a = document.createElement('a'); a.href = m.url; a.className = 'block px-1 py-2.5 hover:bg-mist/50';
                    a.innerHTML = '<span class="block text-sm font-medium tabular-nums"></span><span class="block truncate text-xs text-steel"></span>';
                    a.children[0].textContent = m.code; a.children[1].textContent = m.name + (m.sub ? ' · ' + m.sub : ''); li.appendChild(a); list.appendChild(li);
                });
            });
        }, 220);
    });
})();
