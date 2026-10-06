/*
 | Shared screens behaviour (no framework):
 |   display size       [data-scale] buttons in the top bar
 |   delete dialog      [data-delete-url] / [data-delete-bulk] — the person must type DELETE
 |   lists              [data-table]: tick rows, bulk bar, choose columns (saved per person)
 |   forms              [data-form]: required-field counter + dialog, wizard steps, full screen
 |   rich text          [data-richtext]: Quill, loaded on demand, images uploaded to the server and resizable
 */
(function () {
    'use strict';

    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    function csrf() { var m = $('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function postJson(url, body) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body || {}) })
            .then(function (r) { return r.json().catch(function () { return {}; }); });
    }
    function closeMenus() { document.body.dispatchEvent(new MouseEvent('click', { bubbles: true })); }

    /* ------------------------------------------------------ display size */

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-scale]');
        if (!b) return;
        var pct = parseInt(b.dataset.scale, 10), menu = $('#scale-menu');
        document.documentElement.style.fontSize = pct + '%';
        $$('[data-scale]').forEach(function (x) {
            var on = x === b;
            ['bg-graphite-900', 'text-white', 'ring-graphite-900', 'hover:bg-graphite-900'].forEach(function (c) { x.classList.toggle(c, on); });
        });
        $$('[data-scale-label]').forEach(function (l) { l.textContent = pct + '%'; });
        if (menu) postJson(menu.dataset.url, { scale: pct });
    });

    /* ---------------------------------------------------- delete dialog */

    var dlg = $('#delete-dialog');
    if (dlg) {
        var form = $('form', dlg), input = $('[data-delete-input]', dlg), go = $('[data-delete-go]', dlg), idsBox = $('[data-delete-ids]', dlg);
        var fill = function (tpl, vars) { return tpl.replace(/:(\w+)/g, function (m, k) { return vars[k] !== undefined ? vars[k] : m; }); };
        var openDelete = function (url, kind, name, ids) {
            closeMenus();
            form.action = url;
            idsBox.innerHTML = '';
            (ids || []).forEach(function (id) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; idsBox.appendChild(i); });
            $('[data-delete-text]', dlg).textContent = ids && ids.length ? fill(dlg.dataset.msgMany, { n: ids.length, kind: kind }) : fill(dlg.dataset.msgOne, { kind: kind, name: name });
            input.value = ''; go.disabled = true;
            dlg.showModal();
            input.focus();
        };
        document.addEventListener('click', function (e) {
            var one = e.target.closest('[data-delete-url]'), many = e.target.closest('[data-delete-bulk]');
            if (one) { e.preventDefault(); openDelete(one.dataset.deleteUrl, one.dataset.deleteKind || '', one.dataset.deleteName || '', null); }
            if (many) {
                var ids = $$('[data-select-row]:checked').map(function (c) { return c.value; });
                if (ids.length) openDelete(many.dataset.deleteBulk, many.dataset.deleteKind || '', '', ids);
            }
            if (e.target === dlg || e.target.closest('[data-delete-cancel]')) dlg.close();
        });
        input.addEventListener('input', function () { go.disabled = input.value !== 'DELETE'; });
        form.addEventListener('submit', function (e) { if (input.value !== 'DELETE') e.preventDefault(); });
    }

    /* ------------------------------------------------------------ lists */

    $$('[data-table]').forEach(function (box) {
        var all = $('[data-select-all]', box), bulk = $('[data-bulk]', box);
        var rows = function () { return $$('[data-select-row]', box); };
        function sync() {
            var sel = rows().filter(function (c) { return c.checked; });
            if (bulk) { bulk.hidden = !sel.length; $('[data-bulk-count]', bulk).textContent = sel.length; }
            if (all) { all.checked = sel.length > 0 && sel.length === rows().length; all.indeterminate = sel.length > 0 && sel.length < rows().length; }
            rows().forEach(function (c) { var tr = c.closest('tr'); if (tr) tr.classList.toggle('bg-signal-50/50', c.checked); });
        }
        box.addEventListener('change', function (e) {
            if (e.target === all) rows().forEach(function (c) { c.checked = all.checked; });
            if (e.target === all || e.target.matches('[data-select-row]')) sync();
            if (e.target.matches('[data-col-toggle]')) { applyColumns(); saveColumns(); }
        });
        box.addEventListener('click', function (e) {
            if (e.target.closest('[data-bulk-clear]')) { rows().forEach(function (c) { c.checked = false; }); sync(); }
            if (e.target.closest('[data-col-reset]')) { $$('[data-col-toggle]', box).forEach(function (c) { c.checked = true; }); applyColumns(); saveColumns(); }
        });
        function applyColumns() {
            $$('[data-col-toggle]', box).forEach(function (c) { $$('[data-col="' + c.dataset.colToggle + '"]', box).forEach(function (el) { el.hidden = !c.checked; }); });
        }
        function saveColumns() {
            var hidden = $$('[data-col-toggle]', box).filter(function (c) { return !c.checked; }).map(function (c) { return c.dataset.colToggle; });
            postJson(box.dataset.prefsUrl, { resource: box.dataset.table, hidden: hidden });
        }
        sync();
    });

    /* ------------------------------------------------------------ forms */

    function initForm(form) {
        var fields = $$('[data-field]', form), req = fields.filter(function (f) { return f.hasAttribute('data-required'); });
        var visited = {};
        var steps = $$('[data-step]', form), wizard = form.hasAttribute('data-wizard') && steps.length > 1, cur = 0;
        var save = $('[data-save]', form), next = $('[data-next]', form), prev = $('[data-prev]', form), summary = $('[data-req-summary]', form);
        var reqDlg = $('#required-dialog', form), list = $('[data-req-list]', form), dots = $$('[data-go]', form);

        function active(f) { var h = f.closest('[data-crm-show]'); return !(h && h.hidden); }
        function filled(f) {
            var sel = $('[data-select-input]', f);
            if (sel) return sel.value !== '';
            var t = $('input:not([type=hidden]):not([type=file]), textarea', f);
            if (!t) return true;
            return t.type === 'checkbox' ? t.checked : t.value.trim() !== '';
        }
        function stepOf(f) { var s = f.closest('[data-step]'); return s ? steps.indexOf(s) : 0; }
        function ctl(f) { return $('[data-select-button]', f) || $('input:not([type=hidden]):not([type=file]), textarea, .ql-editor', f); }

        function recompute() {
            var live = req.filter(active), missing = live.filter(function (f) { return !filled(f); });
            var total = live.length, done = total - missing.length;
            $$('[data-req-filled]', form).forEach(function (n) { n.textContent = done; });
            $$('[data-req-total]', form).forEach(function (n) { n.textContent = total; });
            var bar = $('[data-req-bar]', form), pct = total ? Math.round(done / total * 100) : 100;
            if (bar) { bar.style.width = pct + '%'; bar.classList.toggle('bg-emerald-600', pct === 100); bar.classList.toggle('bg-signal-600', pct < 100); }
            var pr = $('[data-req-progress]', form); if (pr) pr.setAttribute('aria-valuenow', pct);
            if (summary) summary.hidden = total === 0;
            if (save) save.disabled = missing.length > 0;

            var perStep = steps.map(function (s, i) { return missing.filter(function (f) { return stepOf(f) === i; }).length; });
            var reach = true;
            dots.forEach(function (b, i) {
                var n = perStep[i], isCur = i === cur, dot = $('[data-dot]', b), note = $('[data-step-note]', b);
                b.disabled = !reach && i !== cur;
                if (n > 0) reach = false;
                b.classList.toggle('ring-2', isCur); b.classList.toggle('!ring-signal-600', isCur);
                var done = n === 0 && i !== cur && (visited[i] || stepHasReq(i));
                dot.textContent = done ? '✓' : (i + 1);
                dot.className = 'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ' + (done ? 'bg-emerald-600 text-white' : (isCur ? 'bg-signal-600 text-white' : 'bg-graphite-900/8'));
                note.textContent = n > 0 ? n + ' ' + (form.dataset.msgMissing || 'required missing') : (stepHasReq(i) ? (form.dataset.msgDone || 'Complete') : (form.dataset.msgOptional || 'Optional'));
            });
            if (next) { next.disabled = perStep[cur] > 0; next.hidden = cur >= steps.length - 1; }
            if (prev) prev.disabled = cur === 0;
            if (reqDlg && reqDlg.open) buildList(live);
        }
        function stepHasReq(i) { return req.some(function (f) { return stepOf(f) === i; }); }
        function stepComplete(i) { return !req.some(function (f) { return stepOf(f) === i && active(f) && !filled(f); }); }
        function show(i) {
            if (!wizard) return;
            visited[cur] = true; cur = Math.max(0, Math.min(steps.length - 1, i));
            steps.forEach(function (s, k) { s.hidden = k !== cur; });
            recompute();
            var top = $('[data-stepper]', form); if (top) top.scrollIntoView({ block: 'nearest' });
        }
        function buildList(live) {
            list.innerHTML = '';
            live.forEach(function (f) {
                var ok = filled(f), li = document.createElement('li'), b = document.createElement('button');
                b.type = 'button'; b.dataset.goto = f.dataset.field;
                b.className = 'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-mist';
                b.innerHTML = '<span class="flex size-5 shrink-0 items-center justify-center rounded-full ' + (ok ? 'bg-emerald-600 text-white' : 'ring-2 ring-graphite-900/25') + '">' + (ok ? '✓' : '') + '</span>' +
                    '<span class="min-w-0 flex-1 truncate ' + (ok ? 'text-steel' : 'font-medium') + '"></span>' + (wizard ? '<span class="text-xs text-steel"></span>' : '');
                $('span:nth-child(2)', b).textContent = f.dataset.label;
                if (wizard) $('span:nth-child(3)', b).textContent = $('.panel-title', steps[stepOf(f)]).textContent;
                li.appendChild(b); list.appendChild(li);
            });
        }
        function goField(name) {
            var f = $('[data-field="' + name + '"]', form); if (!f) return;
            if (wizard) show(stepOf(f));
            var c = ctl(f); if (c) { c.focus(); f.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        }

        form.addEventListener('input', recompute);
        form.addEventListener('change', recompute);
        form.addEventListener('click', function (e) {
            var t;
            if ((t = e.target.closest('[data-go]')) && !t.disabled) show(parseInt(t.dataset.go, 10));
            else if (e.target.closest('[data-next]')) show(cur + 1);
            else if (e.target.closest('[data-prev]')) show(cur - 1);
            else if (e.target.closest('[data-req-open]')) { buildList(req.filter(active)); reqDlg.showModal(); }
            else if (e.target.closest('[data-req-close]')) reqDlg.close();
            else if ((t = e.target.closest('[data-goto]'))) { reqDlg.close(); goField(t.dataset.goto); }
            else if (e.target.closest('[data-fullscreen]')) {
                var shell = $('#form-shell');
                if (document.fullscreenElement) document.exitFullscreen(); else if (shell.requestFullscreen) shell.requestFullscreen();
            }
        });
        if (reqDlg) reqDlg.addEventListener('click', function (e) { if (e.target === reqDlg) reqDlg.close(); });
        form.addEventListener('submit', function (e) {
            $$('[data-richtext]', form).forEach(function (b) { if (b._sync) b._sync(); });
            var missing = req.filter(active).filter(function (f) { return !filled(f); });
            if (missing.length) { e.preventDefault(); buildList(req.filter(active)); reqDlg.showModal(); return; }
            if (save) setTimeout(function () { save.disabled = true; }, 0);
        });
        // a server error sends the person to the step that has it
        var start = parseInt(form.dataset.start || '0', 10);
        if (wizard) { cur = start; steps.forEach(function (s, k) { s.hidden = k !== cur; }); for (var k = 0; k < cur; k++) visited[k] = true; }
        recompute();
        setTimeout(recompute, 60);   // dropdowns and the editor fill in after load
    }
    $$('[data-form]').forEach(initForm);

    /* -------------------------------------------------------- rich text */

    var quillQueue = null;
    function loadQuill(base, cb) {
        if (window.Quill) return cb();
        if (quillQueue) { quillQueue.push(cb); return; }
        quillQueue = [cb];
        var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = base + '/quill.snow.css'; document.head.appendChild(l);
        var s = document.createElement('script'); s.src = base + '/quill.js';
        s.onload = function () { quillQueue.forEach(function (f) { f(); }); };
        document.head.appendChild(s);
    }

    function initRichtext(box) {
        var ta = $('textarea', box), host = $('[data-rt-editor]', box);
        var q = new window.Quill(host, {
            theme: 'snow',
            modules: { toolbar: { container: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline', 'strike'], [{ list: 'ordered' }, { list: 'bullet' }], [{ align: [] }], ['blockquote', 'link', 'image'], ['clean']], handlers: { image: pickImage } } }
        });
        var label = box.dataset.labelledby; if (label) q.root.setAttribute('aria-labelledby', label);
        if (ta.value.trim()) q.setContents(q.clipboard.convert({ html: ta.value }), 'silent');
        function sync() {
            var empty = q.getText().trim() === '' && !q.root.querySelector('img');
            ta.value = empty ? '' : q.getSemanticHTML().replace(/&nbsp;/g, ' ');   // Quill writes every space as a non-breaking one
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }
        box._sync = sync;
        q.on('text-change', sync);

        // pictures are uploaded, never pasted in as huge inline data
        var Delta = window.Quill.import('delta');
        q.clipboard.addMatcher('IMG', function (node, delta) { return /^data:/i.test(node.getAttribute('src') || '') ? new Delta() : delta; });
        q.root.addEventListener('paste', function (e) {
            var files = Array.prototype.filter.call((e.clipboardData && e.clipboardData.files) || [], function (f) { return /^image\//.test(f.type); });
            if (files.length) { e.preventDefault(); files.forEach(upload); }
        });
        q.root.addEventListener('drop', function (e) {
            var files = Array.prototype.filter.call((e.dataTransfer && e.dataTransfer.files) || [], function (f) { return /^image\//.test(f.type); });
            if (files.length) { e.preventDefault(); files.forEach(upload); }
        });
        function pickImage() {
            var inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/png,image/jpeg,image/webp,image/gif';
            inp.onchange = function () { if (inp.files[0]) upload(inp.files[0]); };
            inp.click();
        }
        function upload(file) {
            var fd = new FormData(); fd.append('image', file); fd.append('_token', csrf());
            var range = q.getSelection(true) || { index: q.getLength() };
            fetch(box.dataset.upload, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: fd })
                .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message || 'Upload failed'); return d; }); })
                .then(function (d) { q.insertEmbed(range.index, 'image', d.url, 'user'); q.setSelection(range.index + 1, 0, 'silent'); })
                .catch(function (err) { window.alert(err.message); });
        }
        resizable(q, box);
    }

    /* Click a picture to get drag handles (and 25 / 50 / 100 % buttons) */
    function resizable(q, box) {
        var wrap = q.container, frame = document.createElement('div'), img = null;
        frame.className = 'rt-frame'; frame.hidden = true;
        frame.innerHTML = '<span class="rt-handle" data-corner="nw"></span><span class="rt-handle" data-corner="ne"></span><span class="rt-handle" data-corner="sw"></span><span class="rt-handle" data-corner="se"></span>' +
            '<span class="rt-sizes"><button type="button" data-w="25">25%</button><button type="button" data-w="50">50%</button><button type="button" data-w="100">100%</button></span>';
        wrap.style.position = 'relative'; wrap.appendChild(frame);
        function place() {
            if (!img || !img.isConnected) { frame.hidden = true; return; }
            var c = wrap.getBoundingClientRect(), r = img.getBoundingClientRect();
            frame.style.left = (r.left - c.left) + 'px'; frame.style.top = (r.top - c.top) + 'px'; frame.style.width = r.width + 'px'; frame.style.height = r.height + 'px';
            frame.hidden = false;
        }
        function apply(w) {
            var blot = window.Quill.find(img); if (!blot) return;
            var index = q.getIndex(blot);
            q.formatText(index, 1, { width: String(Math.round(w)), height: false }, 'user');
            img.style.width = ''; img.style.height = '';
            requestAnimationFrame(place);
        }
        q.root.addEventListener('click', function (e) { if (e.target.tagName === 'IMG') { img = e.target; place(); } else { img = null; frame.hidden = true; } });
        q.on('text-change', function () { requestAnimationFrame(place); });
        q.root.addEventListener('scroll', place);
        window.addEventListener('resize', place);
        frame.addEventListener('click', function (e) {
            var b = e.target.closest('[data-w]'); if (!b || !img) return;
            apply(Math.max(40, q.root.clientWidth * parseInt(b.dataset.w, 10) / 100 - 32));
        });
        frame.addEventListener('mousedown', function (e) {
            var h = e.target.closest('.rt-handle'); if (!h || !img) return;
            e.preventDefault();
            var startX = e.clientX, startW = img.getBoundingClientRect().width, left = /w$/.test(h.dataset.corner), w = startW;
            function move(ev) {
                w = Math.max(40, Math.min(q.root.clientWidth - 32, startW + (left ? startX - ev.clientX : ev.clientX - startX)));
                img.style.width = w + 'px'; img.style.height = 'auto'; place();
            }
            function up() { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); apply(w); }
            document.addEventListener('mousemove', move); document.addEventListener('mouseup', up);
        });
    }

    var boxes = $$('[data-richtext]');
    if (boxes.length) loadQuill(boxes[0].dataset.assets, function () { boxes.forEach(initRichtext); });
})();
