/*
 | User Management → Sign PDF editor (#pdf-sign).
 | pdf.js draws the pages; the person's own signature (loaded from the server, never chosen) is added as boxes that can be
 | moved and resized (mouse, touch, arrow keys; Delete removes). "Sign & save" stamps the boxes into the PDF with pdf-lib in
 | the browser, then sends the original and the signed copy with the box positions to the server, which keeps both.
 */
(function () {
    'use strict';
    var root = document.getElementById('pdf-sign');
    if (!root || !window.pdfjsLib || !window.PDFLib) return;
    var L = root.dataset, $ = function (s) { return root.querySelector(s); };
    pdfjsLib.GlobalWorkerOptions.workerSrc = L.worker;

    var st = { file: null, bytes: null, pages: [], sigBytes: null, sigUrl: '', ratio: 3, boxes: [], sel: null, busy: false };
    var pagesBox = $('[data-pages]'), drop = $('[data-drop]'), bar = $('[data-bar]'), input = $('[data-file]');
    function toast(m, t) { if (window.toast) window.toast(m, t || 'error'); else alert(m); }

    // the signed-in person's own signature
    fetch(L.signature, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.arrayBuffer() : Promise.reject(); }).then(function (buf) {
        st.sigBytes = new Uint8Array(buf);
        st.sigUrl = URL.createObjectURL(new Blob([buf], { type: 'image/png' }));
        var im = new Image(); im.onload = function () { st.ratio = im.naturalWidth / Math.max(1, im.naturalHeight); }; im.src = st.sigUrl;
    }).catch(function () { toast(L.lNoSig); });

    /* ------------------------------------------------------------ open a PDF */

    input.addEventListener('change', function () { if (input.files[0]) open(input.files[0]); });
    ['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('ring-2', 'ring-signal-600'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('ring-2', 'ring-signal-600'); }); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); var f = e.dataTransfer.files[0]; if (f) open(f); });

    function open(file) {
        if (!/\.pdf$/i.test(file.name) && file.type !== 'application/pdf') { toast(L.lNotPdf); return; }
        if (file.size > parseInt(L.max, 10)) { toast(L.lTooBig); return; }
        file.arrayBuffer().then(function (buf) {
            st.file = file; st.bytes = new Uint8Array(buf); st.boxes = []; st.pages = []; st.sel = null;
            return pdfjsLib.getDocument({ data: new Uint8Array(buf.slice(0)) }).promise;
        }).then(function (pdf) {
            drop.hidden = true; bar.hidden = false; pagesBox.innerHTML = '';
            $('[data-name]').textContent = file.name;
            $('[data-count]').textContent = pdf.numPages;
            return renderAll(pdf);
        }).catch(function (e) {
            toast(e && e.name === 'PasswordException' ? L.lLocked : L.lBroken);
        });
    }

    function renderAll(pdf) {
        var n = Math.min(pdf.numPages, 300), chain = Promise.resolve();
        for (var i = 1; i <= n; i++) chain = chain.then(renderPage.bind(null, pdf, i));
        return chain.then(update);
    }

    function renderPage(pdf, num) {
        return pdf.getPage(num).then(function (page) {
            var vp1 = page.getViewport({ scale: 1 });
            var width = Math.min(pagesBox.clientWidth - 2, 900), scale = width / vp1.width, dpr = Math.min(window.devicePixelRatio || 1, 2);
            var vp = page.getViewport({ scale: scale * dpr });
            var wrap = document.createElement('div');
            wrap.className = 'relative mx-auto bg-white shadow-md ring-1 ring-graphite-900/10';
            wrap.style.width = width + 'px'; wrap.style.height = Math.round(vp1.height * scale) + 'px';
            wrap.dataset.page = num;
            var canvas = document.createElement('canvas');
            canvas.width = Math.floor(vp.width); canvas.height = Math.floor(vp.height);
            canvas.style.width = '100%'; canvas.style.height = '100%'; canvas.className = 'block';
            var layer = document.createElement('div'); layer.className = 'absolute inset-0'; layer.dataset.layer = num;
            var tag = document.createElement('span');
            tag.className = 'absolute -left-1 -top-6 text-xs text-steel'; tag.textContent = L.lPage.replace(':n', num) + ' / ' + pdf.numPages;
            wrap.append(canvas, layer, tag);
            pagesBox.appendChild(wrap);
            st.pages[num - 1] = { num: num, vp1: vp1, wrap: wrap, layer: layer };
            return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
        });
    }

    /* ------------------------------------------------------ signature boxes */

    function currentPage() {
        var mid = window.innerHeight / 2, best = st.pages[0], bestD = Infinity;
        st.pages.forEach(function (p) { if (!p) return; var r = p.wrap.getBoundingClientRect(), d = Math.abs((r.top + r.bottom) / 2 - mid); if (d < bestD) { bestD = d; best = p; } });
        return best;
    }

    $('[data-add]').addEventListener('click', function () {
        if (!st.sigBytes) { toast(L.lNoSig); return; }
        var p = currentPage(); if (!p) return;
        var w = 0.28, h = w * p.vp1.width / st.ratio / p.vp1.height;
        add({ page: p.num, x: 0.5 - w / 2, y: Math.min(0.9 - h, 0.5 - h / 2), w: w, h: h });
    });

    function add(b) {
        var p = st.pages[b.page - 1];
        var el = document.createElement('div');
        el.className = 'sig-box absolute cursor-move select-none rounded border-2 border-dashed border-signal-600 bg-signal-50/20 focus:outline-none';
        el.tabIndex = 0; el.style.touchAction = 'none'; el.setAttribute('role', 'img'); el.setAttribute('aria-label', L.lSignature);
        el.innerHTML = '<img alt="" draggable="false" class="pointer-events-none h-full w-full object-contain">' +
            '<button type="button" data-del class="absolute -right-3 -top-3 flex size-6 items-center justify-center rounded-full bg-signal-600 text-sm font-bold leading-none text-white shadow" aria-label="' + L.lRemove + '">×</button>' +
            '<span data-resize class="absolute -bottom-2 -right-2 size-4 cursor-nwse-resize rounded-sm border-2 border-white bg-signal-600 shadow"></span>';
        el.querySelector('img').src = st.sigUrl;
        b.el = el; p.layer.appendChild(el); st.boxes.push(b); place(b); select(b); update();
    }

    function place(b) { var s = b.el.style; s.left = b.x * 100 + '%'; s.top = b.y * 100 + '%'; s.width = b.w * 100 + '%'; s.height = b.h * 100 + '%'; }
    function clamp(b) { b.w = Math.min(Math.max(b.w, 0.04), 1); b.h = Math.min(b.h, 1); b.x = Math.min(Math.max(b.x, 0), 1 - b.w); b.y = Math.min(Math.max(b.y, 0), 1 - b.h); }
    function select(b) { st.boxes.forEach(function (x) { x.el.classList.toggle('border-solid', x === b); x.el.classList.toggle('border-dashed', x !== b); }); st.sel = b; if (b) b.el.focus({ preventScroll: true }); }
    function remove(b) { b.el.remove(); st.boxes = st.boxes.filter(function (x) { return x !== b; }); st.sel = null; update(); }
    function boxOf(el) { return st.boxes.filter(function (b) { return b.el === el; })[0]; }

    pagesBox.addEventListener('pointerdown', function (e) {
        var el = e.target.closest('.sig-box'); if (!el) { select(null); return; }
        var b = boxOf(el); if (!b) return;
        if (e.target.closest('[data-del]')) { e.preventDefault(); remove(b); return; }
        e.preventDefault(); select(b);
        var layer = el.parentNode.getBoundingClientRect(), sx = e.clientX, sy = e.clientY, o = { x: b.x, y: b.y, w: b.w, h: b.h };
        var resizing = !!e.target.closest('[data-resize]'), ratioPx = (o.w * layer.width) / (o.h * layer.height);
        try { el.setPointerCapture(e.pointerId); } catch (x) { /* keep dragging without capture */ }
        function move(ev) {
            var dx = (ev.clientX - sx) / layer.width, dy = (ev.clientY - sy) / layer.height;
            if (resizing) { b.w = o.w + dx; b.h = (b.w * layer.width) / ratioPx / layer.height; b.x = o.x; b.y = o.y; }
            else { b.x = o.x + dx; b.y = o.y + dy; }
            clamp(b); place(b);
        }
        function up() { el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', up); }
        el.addEventListener('pointermove', move); el.addEventListener('pointerup', up); el.addEventListener('pointercancel', up);
    });
    pagesBox.addEventListener('keydown', function (e) {
        var b = st.sel; if (!b || document.activeElement !== b.el) return;
        var step = e.shiftKey ? 0.02 : 0.004, k = e.key;
        if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); remove(b); return; }
        var d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[k];
        if (d) { e.preventDefault(); b.x += d[0]; b.y += d[1]; clamp(b); place(b); }
    });

    function update() {
        var n = st.boxes.length;
        $('[data-n]').textContent = n;
        $('[data-save]').disabled = !n || st.busy;
    }

    /* -------------------------------------------------------- sign & save */

    $('[data-restart]').addEventListener('click', function () {
        if (st.boxes.length && !confirm(L.lRestart)) return;
        st = Object.assign(st, { file: null, bytes: null, pages: [], boxes: [], sel: null });
        pagesBox.innerHTML = ''; bar.hidden = true; drop.hidden = false; input.value = '';
    });

    $('[data-save]').addEventListener('click', function () {
        if (!st.boxes.length || st.busy) return;
        var btn = this; st.busy = true; update(); btn.classList.add('opacity-60');
        var placements = st.boxes.map(function (b) { return { page: b.page, x: +b.x.toFixed(5), y: +b.y.toFixed(5), w: +b.w.toFixed(5), h: +b.h.toFixed(5) }; });
        PDFLib.PDFDocument.load(st.bytes).then(function (doc) {
            return doc.embedPng(st.sigBytes).then(function (png) {
                st.boxes.forEach(function (b) {
                    var p = st.pages[b.page - 1], vp = p.vp1, page = doc.getPage(b.page - 1);
                    var dx = b.x * vp.width, dy = b.y * vp.height, dw = b.w * vp.width, dh = b.h * vp.height;
                    // the box's corners in PDF space (pdf.js knows the page's crop box and rotation)
                    var bl = vp.convertToPdfPoint(dx, dy + dh), br = vp.convertToPdfPoint(dx + dw, dy + dh), tl = vp.convertToPdfPoint(dx, dy);
                    var w = Math.hypot(br[0] - bl[0], br[1] - bl[1]), h = Math.hypot(tl[0] - bl[0], tl[1] - bl[1]);
                    var angle = Math.atan2(br[1] - bl[1], br[0] - bl[0]) * 180 / Math.PI;
                    page.drawImage(png, { x: bl[0], y: bl[1], width: w, height: h, rotate: PDFLib.degrees(angle) });
                });
                return doc.save();
            });
        }).then(function (bytes) {
            var fd = new FormData();
            fd.append('original', st.file, st.file.name);
            fd.append('signed', new Blob([bytes], { type: 'application/pdf' }), 'signed.pdf');
            fd.append('placements', JSON.stringify(placements));
            fd.append('pages', String(st.pages.length));
            fd.append('_token', L.token);
            return fetch(L.store, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': L.token } })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || L.lFailed); return d; }); });
        }).then(function (d) { location.href = d.url; })
          .catch(function (e) { toast(/encrypt/i.test(String(e && e.message)) ? L.lLocked : (e && e.message) || L.lFailed); st.busy = false; btn.classList.remove('opacity-60'); update(); });
    });
})();
