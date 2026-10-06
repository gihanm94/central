/*
 | Shared screens behaviour (no framework):
 |   display size       [data-scale] buttons in the top bar
 |   delete dialog      [data-delete-url] — the person must type DELETE
 |   lists              [data-table]: live search (3+ letters), choose columns (saved per person)
 |   remote dropdowns   [data-remote-select]: search + endless scroll against the server
 |   bell               notifications polled once a minute
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
        var openDelete = function (url, kind, name) {
            closeMenus();
            form.action = url;
            idsBox.innerHTML = '';
            $('[data-delete-text]', dlg).textContent = fill(dlg.dataset.msgOne, { kind: kind, name: name });
            input.value = ''; go.disabled = true;
            dlg.showModal();
            input.focus();
        };
        document.addEventListener('click', function (e) {
            var one = e.target.closest('[data-delete-url]');
            if (one) { e.preventDefault(); openDelete(one.dataset.deleteUrl, one.dataset.deleteKind || '', one.dataset.deleteName || ''); }
            if (e.target === dlg || e.target.closest('[data-delete-cancel]')) dlg.close();
        });
        input.addEventListener('input', function () { go.disabled = input.value !== 'DELETE'; });
        form.addEventListener('submit', function (e) { if (input.value !== 'DELETE') e.preventDefault(); });
    }

    /* ------------------------------------------------------------ lists */

    $$('[data-table]').forEach(function (box) {
        // The column menu opens from <body>, so its controls are found on the document, not inside the table box.
        document.addEventListener('change', function (e) {
            if (e.target.matches('[data-col-toggle]')) { applyColumns(); saveColumns(); }
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-col-reset]')) { $$('[data-col-toggle]').forEach(function (c) { c.checked = true; }); applyColumns(); saveColumns(); }
        });
        function applyColumns() {
            $$('[data-col-toggle]').forEach(function (c) { $$('[data-col="' + c.dataset.colToggle + '"]', box).forEach(function (el) { el.hidden = !c.checked; }); });
        }
        function saveColumns() {
            var hidden = $$('[data-col-toggle]').filter(function (c) { return !c.checked; }).map(function (c) { return c.dataset.colToggle; });
            postJson(box.dataset.prefsUrl, { resource: box.dataset.table, hidden: hidden });
        }

        // Search as you type: starts at 3 letters, and an emptied box brings the normal list back.
        var form = $('#list-form', box), q = form && $('input[name=q]', form), region = $('[data-list-region]', box), timer, last = q ? q.value.trim() : '', seq = 0;
        if (q && region) {
            q.addEventListener('input', function () {
                var v = q.value.trim();
                clearTimeout(timer);
                if (v === last || (v.length > 0 && v.length < 3)) return;
                timer = setTimeout(function () { run(v); }, 350);
            });
            q.addEventListener('keydown', function (e) { if (e.key === 'Escape' && q.value) { q.value = ''; q.dispatchEvent(new Event('input')); } });
        }
        function run(v) {
            last = v; var mine = ++seq;
            var params = new URLSearchParams(new FormData(form));
            params.delete('page');
            Array.from(params.keys()).forEach(function (k) { if (params.get(k) === '') params.delete(k); });
            var url = location.pathname + (params.toString() ? '?' + params.toString() : '');
            region.setAttribute('aria-busy', 'true'); region.classList.add('opacity-60');
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    if (mine !== seq) return;
                    var doc = new DOMParser().parseFromString(html, 'text/html'), fresh = $('[data-list-region]', doc), total = $('[data-total]', doc), here = $('[data-total]');
                    if (fresh) region.innerHTML = fresh.innerHTML;
                    if (total && here) here.innerHTML = total.innerHTML;
                    history.replaceState(null, '', url);
                })
                .finally(function () { if (mine === seq) { region.removeAttribute('aria-busy'); region.classList.remove('opacity-60'); } });
        }
    });

    /* -------------------------------------------- remote (endless) dropdowns */

    function initials(name) { var p = String(name).trim().split(/\s+/); return ((p[0] || '').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase(); }
    function richNode(it) {
        var wrap = document.createElement('span'); wrap.className = 'flex min-w-0 items-center gap-2.5';
        var pic;
        if (it.img) { pic = document.createElement('img'); pic.src = it.img; pic.alt = ''; pic.className = 'size-8 shrink-0 rounded bg-white object-contain ring-1 ring-graphite-900/10'; }
        else { pic = document.createElement('span'); pic.className = 'inline-flex size-8 shrink-0 items-center justify-center rounded bg-graphite-900/6 text-[11px] font-semibold text-graphite-700'; pic.textContent = initials(it.label); }
        var txt = document.createElement('span'); txt.className = 'min-w-0 text-left';
        var a = document.createElement('span'); a.className = 'block truncate leading-tight'; a.textContent = it.label; txt.appendChild(a);
        if (it.sub) { var b = document.createElement('span'); b.className = 'block truncate text-xs leading-tight text-steel'; b.textContent = it.sub; txt.appendChild(b); }
        wrap.appendChild(pic); wrap.appendChild(txt);
        return wrap;
    }
    function depValue(sel) { var d = sel.dataset.depends; if (!d) return ''; var el = document.querySelector('[name="' + d + '"]'); return el ? el.value : ''; }
    function panelOf(sel) { return $$('[data-select-panel][data-remote]').filter(function (p) { return p._select === sel && !p.hidden; })[0]; }

    function remoteLoad(sel, panel, reset) {
        var st = sel._rs || (sel._rs = { q: '', page: 0, more: true, loading: false, seq: 0, dep: null, loaded: false });
        var items = $('[data-remote-items]', panel), status = $('[data-remote-status]', panel), hidden = $('[data-select-input]', sel);
        if (reset) { st.page = 0; st.more = true; st.loading = false; st.seq++; items.innerHTML = ''; st.loaded = false; }
        if (st.loading || !st.more) return;
        st.loading = true; var my = st.seq; status.textContent = sel.dataset.msgLoading || 'Loading…';
        var params = new URLSearchParams({ q: st.q, page: st.page + 1 }); var dep = depValue(sel); if (dep) params.set('lead', dep);
        fetch(sel.dataset.url + '?' + params.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (my !== st.seq) return;
                d.items.forEach(function (it) {
                    var b = document.createElement('button'); b.type = 'button'; b.setAttribute('role', 'option');
                    b.dataset.value = it.id; b.dataset.label = it.label; b.setAttribute('aria-selected', String(String(hidden.value) === String(it.id)));
                    b.className = 'group flex w-full items-center justify-between gap-3 rounded-md px-2.5 py-1.5 text-left text-sm hover:bg-mist focus:bg-mist focus:outline-none aria-selected:font-medium';
                    var r = document.createElement('span'); r.className = 'min-w-0'; r.setAttribute('data-rich', ''); r.appendChild(richNode(it)); b.appendChild(r);
                    items.appendChild(b);
                });
                st.page++; st.more = d.more; st.loaded = true;
                status.textContent = items.children.length ? (d.more ? '' : '') : (sel.dataset.msgNone || 'No matches');
            })
            .catch(function () { status.textContent = sel.dataset.msgError || 'Could not load. Try again.'; })
            .finally(function () { if (my === st.seq) st.loading = false; });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remote-select] [data-select-button]');
        if (!b) return;
        var sel = b.closest('[data-remote-select]');
        setTimeout(function () {
            var panel = panelOf(sel); if (!panel) return;
            var st = sel._rs, dep = depValue(sel);
            if (!st || !st.loaded || st.q !== '' || st.dep !== dep) { sel._rs = null; remoteLoad(sel, panel, true); sel._rs.dep = dep; }
        }, 0);
    });
    var searchTimer;
    document.addEventListener('input', function (e) {
        var inp = e.target.closest('[data-remote] [data-select-search]'); if (!inp) return;
        var panel = inp.closest('[data-select-panel]'), sel = panel._select; if (!sel) return;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { sel._rs = sel._rs || {}; var dep = sel._rs.dep; sel._rs = null; sel._rs = { q: inp.value.trim(), page: 0, more: true, loading: false, seq: Math.random(), dep: dep, loaded: false }; remoteLoad(sel, panel, true); sel._rs.q = inp.value.trim(); }, 250);
    });
    document.addEventListener('scroll', function (e) {
        var list = e.target; if (!list.matches || !list.matches('[data-remote] [data-select-list]')) return;
        var panel = list.closest('[data-select-panel]'), sel = panel._select;
        if (sel && list.scrollTop + list.clientHeight >= list.scrollHeight - 48) remoteLoad(sel, panel, false);
    }, true);
    // changing the lead empties the contact / opportunity picked for the old one
    document.addEventListener('change', function (e) {
        var t = e.target; if (!t.matches || !t.matches('[data-select-input]') || !t.name) return;
        $$('[data-remote-select][data-depends="' + t.name + '"]').forEach(function (sel) {
            var inp = $('[data-select-input]', sel); if (!inp.value) return;
            inp.value = ''; var lbl = $('[data-select-label]', sel); lbl.textContent = sel.dataset.placeholder || ''; lbl.classList.add('text-graphite-400'); sel._rs = null;
            inp.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    /* ------------------------------------------------------------- the bell */

    var bell = $('#notif-menu');
    if (bell) {
        var apply = function (d) {
            var badge = $('[data-bell-badge]'); if (!badge) return;
            badge.hidden = !d.unread; badge.textContent = d.unread > 99 ? '99+' : d.unread;
            var list = $('[data-bell-list]', bell); if (list && d.html != null) list.innerHTML = d.html;
        };
        var poll = function () { if (document.hidden) return; fetch(bell.dataset.feedUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (d) apply(d); }).catch(function () {}); };
        setInterval(poll, 60000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
        setTimeout(poll, 1500);   // also fires reminders that came due
        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-bell-read]')) { e.preventDefault(); e.stopPropagation(); postJson(bell.dataset.readUrl, {}).then(function () { poll(); }); }
        });
    }

    document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('select[data-autosubmit]')) e.target.form.submit(); });

    /* ------------------------------------------------------------ forms */

    function initForm(form) {
        if (form._ready) return; form._ready = true;
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

            var perStep = steps.map(function (st, i) { return missing.filter(function (f) { return stepOf(f) === i; }).length; });
            dots.forEach(function (b, i) {
                var n = perStep[i], isCur = i === cur, dot = $('[data-dot]', b), note = $('[data-step-note]', b);
                var done = n === 0 && !isCur && (visited[i] || stepHasReq(i));
                b.className = 'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors ' + (isCur ? 'bg-graphite-900 text-white' : 'hover:bg-graphite-900/5');
                b.setAttribute('aria-current', isCur ? 'step' : 'false');
                dot.textContent = done ? '✓' : (i + 1);
                dot.className = 'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ' +
                    (isCur ? 'bg-white/15 text-white' : (done ? 'bg-emerald-600 text-white' : (n > 0 ? 'bg-signal-50 text-signal-700' : 'bg-graphite-900/8 text-graphite-800')));
                note.textContent = n > 0 ? n + ' ' + (form.dataset.msgMissing || 'required missing') : (stepHasReq(i) ? (form.dataset.msgDone || 'Complete') : (form.dataset.msgOptional || 'Optional'));
                note.className = 'block text-xs ' + (isCur ? 'text-white/70' : (n > 0 ? 'text-signal-700' : (stepHasReq(i) ? 'text-emerald-700' : 'text-steel')));
            });
            if (next) { next.hidden = cur >= steps.length - 1; }
            if (prev) { prev.disabled = cur === 0; }
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
                if (wizard) $('span:nth-child(3)', b).textContent = $('[data-step-title]', steps[stepOf(f)]).textContent;
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
            if ((t = e.target.closest('[data-go]'))) show(parseInt(t.dataset.go, 10));
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

    function bootRich(root) {
        var boxes = $$('[data-richtext]', root).filter(function (b) { return !b._sync; });
        if (boxes.length) loadQuill(boxes[0].dataset.assets, function () { boxes.forEach(function (b) { if (!b._sync) initRichtext(b); }); });
    }
    bootRich(document);

    /* ------------------------------------------------- side sheet
       The very same form page, fetched with ?embed=1 and shown in a slide-over. Saving posts it back as JSON
       and reloads the page behind it, so a related record can be added without leaving the page. */

    var sheet = null, lastFocus = null;
    function sheetBuild() {
        sheet = document.createElement('div');
        sheet.className = 'fixed inset-0 z-[60]'; sheet.hidden = true;
        sheet.innerHTML = '<div class="absolute inset-0 bg-graphite-950/50" data-sheet-close></div>' +
            '<aside role="dialog" aria-modal="true" aria-labelledby="sheet-title" class="absolute inset-y-0 right-0 flex w-[min(52rem,100vw)] flex-col bg-mist shadow-2xl">' +
            '<header class="flex items-center justify-between gap-3 border-b border-graphite-900/10 bg-white px-5 py-3"><h2 id="sheet-title" class="truncate text-base font-semibold"></h2>' +
            '<button type="button" class="btn-ghost !px-2" data-sheet-close aria-label="Close">&times;</button></header>' +
            '<div class="min-h-0 flex-1 overflow-y-auto p-4" data-sheet-body></div></aside>';
        document.body.appendChild(sheet);
        sheet.addEventListener('click', function (e) { if (e.target.closest('[data-sheet-close]')) closeSheet(); });
    }
    function closeSheet(force) {
        if (!sheet || sheet.hidden) return;
        var f = $('form', sheet);
        if (!force && f && f.dataset.dirty && !window.confirm(f.dataset.msgDiscard || 'Discard what you typed?')) return;
        sheet.hidden = true; document.body.classList.remove('overflow-hidden');
        $('[data-sheet-body]', sheet).innerHTML = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    function sheetErrors(form, d) {
        $$('.sheet-err', form).forEach(function (n) { n.remove(); });
        var first = null, errs = (d && d.errors) || {};
        Object.keys(errs).forEach(function (k) {
            var f = $('[data-field="' + k + '"]', form), p = document.createElement('p');
            p.className = 'error sheet-err'; p.textContent = [].concat(errs[k])[0];
            if (f) { f.appendChild(p); first = first || f; }
        });
        if (!first) {
            var b = document.createElement('p'); b.className = 'error sheet-err mb-3 rounded-lg bg-red-50 px-3 py-2'; b.textContent = (d && d.message) || 'Could not save.';
            form.insertBefore(b, form.firstChild);
        } else {
            var step = first.closest('[data-step]'), go = step && $('[data-go="' + step.dataset.step + '"]', form);
            if (go) go.click();
            first.scrollIntoView({ block: 'center' });
        }
    }
    function sheetSubmit(form) {
        form.addEventListener('input', function () { form.dataset.dirty = '1'; });
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;          // required fields missing: the form already told the person
            e.preventDefault();
            var save = $('[data-save]', form); if (save) save.disabled = true;
            fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: new FormData(form) })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (x) {
                    if (x.ok) { form.dataset.dirty = ''; closeSheet(true); location.reload(); return; }
                    sheetErrors(form, x.d); if (save) save.disabled = false;
                })
                .catch(function () { sheetErrors(form, { message: 'Network error. Try again.' }); if (save) save.disabled = false; });
        });
    }
    function openSheet(url, title, opener) {
        if (!sheet) sheetBuild();
        lastFocus = opener || document.activeElement;
        var body = $('[data-sheet-body]', sheet);
        $('#sheet-title', sheet).textContent = title || '';
        body.innerHTML = '<p class="p-6 text-sm text-steel">…</p>';
        sheet.hidden = false; document.body.classList.add('overflow-hidden');
        closeMenus();
        fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'embed=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, t: t }; }); })
            .then(function (x) {
                if (!x.ok) { body.innerHTML = '<p class="p-6 text-sm text-signal-700">' + ((/<p[^>]*>([^<]+)/.exec(x.t) || [])[1] || 'Not available') + '</p>'; return; }
                body.innerHTML = x.t;
                window.AcmeUI.boot(body);
                var f = $('form[data-sheet-form]', body); if (f) sheetSubmit(f);
                var first = $('input:not([type=hidden]):not([type=file]), textarea', body); if (first && !first.closest('[data-select]')) first.focus();
            })
            .catch(function () { body.innerHTML = '<p class="p-6 text-sm text-signal-700">Network error.</p>'; });
    }
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-sheet]');
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;
        e.preventDefault(); openSheet(a.getAttribute('href'), a.dataset.sheetTitle || a.textContent.trim(), a);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet && !sheet.hidden && !document.querySelector('dialog[open]') && !document.querySelector('[data-select-panel]:not([hidden])')) closeSheet(); });

    window.AcmeUI = { boot: function (root) { $$('[data-form]', root).forEach(initForm); bootRich(root); if (window.AcmeCrmApply) window.AcmeCrmApply(); }, sheet: openSheet };
})();
