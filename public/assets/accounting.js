/*
 | Accounting screens.
 |   [data-inet-open]   opens the "Select Invoices to Generate" dialog: search (3+ letters), Invoice / Credit note tabs,
 |                      10 per page, a tick stays when you page on, "Auto PDF" can be set on each ticked row, Generate posts them.
 */
/* ---- shared helpers of the accounting screens ---- */
function postJson(u, data) {
    var m = document.querySelector('meta[name="csrf-token"]');
    return fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': m ? m.content : '' }, body: JSON.stringify(data) })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status)); return d; }); });
}
/* The centre animation of a dialog (markup: accounting/busy.php): spinner while working, a drawn tick when done, then the callback. */
function AcctBusy(root) {
    var box = root.querySelector('[data-busy]'), spin = box.querySelector('[data-busy-spin]'), icon = box.querySelector('[data-busy-icon]'), ok = box.querySelector('[data-busy-ok]'),
        t = box.querySelector('[data-busy-text]'), sub = box.querySelector('[data-busy-sub]');
    var vis = function (el, on) { el.style.display = on ? '' : 'none'; };           // SVG elements have no .hidden property
    return {
        show: function (text, s) { vis(ok, false); vis(spin, true); vis(icon, true); t.textContent = text || ''; sub.textContent = s || ''; box.hidden = false; },
        sub: function (s) { sub.textContent = s || ''; },
        success: function (text, s, then) {
            vis(spin, false); vis(icon, false); vis(ok, true); box.hidden = false; t.textContent = text || ''; sub.textContent = s || '';
            ok.innerHTML = ok.innerHTML;                                              // restart the CSS drawing animation
            setTimeout(function () { if (then) then(); }, 1300);
        },
        hide: function () { box.hidden = true; }
    };
}
/* Ask a Generate job how far it is once a second until it is done. */
function followJob(jobUrl, total, L) {
    return new Promise(function (resolve, reject) {
        var misses = 0;
        (function tick() {
            fetch(jobUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (j) {
                    misses = 0;
                    var box = document.querySelector('#inet-dialog [data-busy-sub]');
                    if (box) box.textContent = j.status === 'done' || j.status === 'failed' ? '' : (j.saved ? L.lStepSave : (j.printed || (j.built >= j.total && j.total)) ? L.lStepPdf : L.lStepBuild) + '  ' + Math.max(j.built, j.saved) + ' / ' + total;
                    if (j.status === 'done') return resolve(j);
                    if (j.status === 'failed') return reject(new Error(j.message || 'Failed'));
                    setTimeout(tick, 900);
                })
                .catch(function (e) { if (++misses > 5) reject(e); else setTimeout(tick, 1500); });
        })();
    });
}

(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var dlg = $('#inet-dialog');
    if (!dlg) return;
    var rowsEl = $('[data-inet-rows]', dlg), pager = $('[data-inet-pager]', dlg), prev = $('[data-inet-prev]', dlg), next = $('[data-inet-next]', dlg),
        go = $('[data-inet-go]', dlg), q = $('[data-inet-q]', dlg), all = $('[data-inet-all]', dlg), empty = $('[data-inet-empty]', dlg), err = $('[data-inet-error]', dlg), count = $('[data-inet-count]', dlg);
    var url = '', credit = dlg.dataset.credit === '1', page = 1, pages = 1, picked = {}, seq = 0, timer, current = [];
    function csrf() { var m = $('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function tabs() {
        dlg.querySelectorAll('[data-inet-tab]').forEach(function (b) {
            var on = (b.dataset.inetTab === '1') === credit;
            b.className = 'rounded-md px-3 py-1 text-sm ' + (on ? 'bg-signal-600 font-medium text-white' : 'text-steel hover:bg-white');
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }
    function summary() {
        var n = Object.keys(picked).length;
        count.textContent = n; go.disabled = n === 0;
        if (all) { var boxes = rowsEl.querySelectorAll('[data-pick]'), on = rowsEl.querySelectorAll('[data-pick]:checked').length; all.checked = boxes.length > 0 && on === boxes.length; all.indeterminate = on > 0 && on < boxes.length; }
    }
    function render() {
        rowsEl.innerHTML = '';
        current.forEach(function (r) {
            var no = String(r.invoice_number), p = picked[no], tr = document.createElement('tr');
            tr.className = 'hover:bg-mist/50';
            tr.innerHTML = '<td class="px-3 py-2.5"><input type="checkbox" data-pick="' + esc(no) + '" class="size-4 accent-signal-600" ' + (p ? 'checked' : '') + ' aria-label="' + esc(no) + '"></td>' +
                '<td class="px-3 py-2.5 font-medium">' + esc(no) + '</td>' +
                '<td class="px-3 py-2.5"><input type="checkbox" data-auto="' + esc(no) + '" class="size-4 accent-signal-600 disabled:opacity-40" ' + (p ? '' : 'disabled') + (p && p.auto ? ' checked' : '') + ' aria-label="Auto PDF"></td>' +
                '<td class="px-3 py-2.5">' + esc(r.order_no) + '</td><td class="px-3 py-2.5">' + esc(r.delivery_no) + '</td><td class="px-3 py-2.5">' + esc(r.vat_no) + '</td><td class="max-w-[14rem] truncate px-3 py-2.5 text-left">' + (r.payment_remark ? '<span class="text-graphite-800" title="' + esc(r.payment_remark) + '">' + esc(r.payment_remark) + '</span>' : '<span class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800 ring-1 ring-amber-200">' + esc(dlg.dataset.lNone || 'none') + '</span>') + '</td><td class="max-w-[12rem] truncate px-3 py-2.5 text-left text-steel" title="' + esc(r.remark) + '">' + esc(r.remark) + '</td>';
            rowsEl.appendChild(tr);
        });
        empty.hidden = current.length > 0; pager.textContent = page + ' / ' + pages; prev.disabled = page <= 1; next.disabled = page >= pages; summary();
    }
    function load() {
        var my = ++seq;
        fetch(url + '?' + new URLSearchParams({ credit: credit ? 1 : 0, q: q.value.trim().length >= 3 ? q.value.trim() : '', page: page }), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (my !== seq) return; current = d.rows || []; pages = d.pages || 1; if (page > pages) { page = pages; } render(); })
            .catch(function () { err.hidden = false; err.textContent = 'Could not load the invoices.'; });
    }
    function reset(c) { credit = c; page = 1; picked = {}; tabs(); load(); }

    document.addEventListener('click', function (e) {
        var o = e.target.closest('[data-inet-open]');
        if (o) { url = o.dataset.url; err.hidden = true; q.value = ''; reset(dlg.dataset.credit === '1'); dlg.showModal(); q.focus(); return; }
        if (e.target.closest('[data-inet-close]')) { dlg.close(); return; }
        var t = e.target.closest('[data-inet-tab]');
        if (t) { reset(t.dataset.inetTab === '1'); return; }
        if (e.target === dlg) dlg.close();
    });
    prev.addEventListener('click', function () { if (page > 1) { page--; load(); } });
    next.addEventListener('click', function () { if (page < pages) { page++; load(); } });
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { if (q.value.trim().length === 0 || q.value.trim().length >= 3) { page = 1; load(); } }, 300); });
    dlg.addEventListener('change', function (e) {
        var p = e.target.closest('[data-pick]'), a = e.target.closest('[data-auto]');
        if (p) {
            var no = p.dataset.pick;
            if (p.checked) picked[no] = { auto: false }; else delete picked[no];
            var au = rowsEl.querySelector('[data-auto="' + no + '"]'); au.disabled = !p.checked; if (!p.checked) au.checked = false;
        } else if (a) { if (picked[a.dataset.auto]) picked[a.dataset.auto].auto = a.checked; }
        else if (e.target === all) {
            rowsEl.querySelectorAll('[data-pick]').forEach(function (b) { if (b.checked !== all.checked) { b.checked = all.checked; b.dispatchEvent(new Event('change', { bubbles: true })); } });
        }
        summary();
    });
    var busy = AcctBusy(dlg), working = false;
    dlg.addEventListener('cancel', function (e) { if (working) e.preventDefault(); });          // Esc must not hide a running job
    go.addEventListener('click', function () {
        var items = Object.keys(picked).map(function (no) { return { invoice: parseInt(no, 10), auto_pdf: picked[no].auto }; });
        if (!items.length) return;
        working = true; err.hidden = true;
        busy.show(dlg.dataset.lWorking, dlg.dataset.lStepBuild);
        postJson(url.replace(/candidates$/, 'generate'), { items: items })
            .then(function (d) { return followJob(url.replace(/candidates$/, 'job/') + d.job, items.length, dlg.dataset); })
            .then(function (j) {
                working = false;
                var msg = j.made + ' / ' + items.length + ' ' + dlg.dataset.lDone.toLowerCase();
                if (j.errors && j.errors.length) {            // some failed: say so, keep what worked
                    busy.hide(); err.hidden = false; err.textContent = j.errors.join(' · ');
                    if (window.toast) toast(msg + ' · ' + j.errors.length + ' ✗', j.made ? 'info' : 'error');
                    dlg.addEventListener('close', function () { location.reload(); }, { once: true });
                    go.disabled = true;
                } else {
                    busy.success(dlg.dataset.lDone, msg, function () { if (window.toast && window.toast.later) toast.later(msg, 'success'); dlg.close(); location.reload(); });
                }
            })
            .catch(function (x) { working = false; busy.hide(); err.hidden = false; err.textContent = x.message; if (window.toast) toast(x.message, 'error'); });
    });
    tabs();
})();

/* ---- Send window + Regenerate (Inets page) ---- */
(function () {
    'use strict';
    var dlg = document.getElementById('inet-send');
    var $ = function (s, r) { return (r || document).querySelector(s); };
    if (dlg) {
        var form = $('[data-send-form]', dlg), file = $('[data-send-file]', dlg), drop = $('[data-send-drop]', dlg), zone = $('[data-send-zone]', dlg), frame = $('[data-send-frame]', dlg),
            bar = $('[data-send-bar]', dlg), barText = $('[data-send-bar-text]', dlg), toggle = $('[data-send-toggle]', dlg), wrap = $('[data-send-toggle-wrap]', dlg), goBtn = $('[data-send-go]', dlg),
            busy = AcctBusy(dlg), cur = null, picked = null, objUrl = null, sending = false;
        function render() {
            var gen = toggle.checked && cur && cur.pdf;
            var showFrame = gen || picked;
            frame.hidden = !showFrame; drop.hidden = !!showFrame; bar.hidden = !showFrame;
            if (gen) { frame.src = cur.pdf + (cur.pdf.indexOf('#') < 0 ? '#toolbar=0' : ''); barText.textContent = dlg.dataset.generated || 'Generated'; }
            else if (picked) { frame.src = objUrl + '#toolbar=0'; barText.textContent = picked.name; }
            else frame.removeAttribute('src');
            goBtn.disabled = !showFrame || sending;
            $('[data-send-clear]', dlg).hidden = !!gen;
        }
        function pick(f) {
            if (!f) return;
            if (f.type !== 'application/pdf' && !/\.pdf$/i.test(f.name) || f.size > 15 * 1024 * 1024) { if (window.toast) toast(dlg.dataset.lBad, 'error'); return; }
            if (objUrl) URL.revokeObjectURL(objUrl);
            picked = f; objUrl = URL.createObjectURL(f); toggle.checked = false; render();
        }
        document.addEventListener('click', function (e) {
            var b = e.target.closest('[data-inet-send]');
            if (!b) return;
            cur = { url: b.dataset.url, pdf: b.dataset.pdf || '' };
            picked = null; file.value = ''; toggle.checked = false; toggle.disabled = !cur.pdf;
            wrap.classList.toggle('opacity-50', !cur.pdf); wrap.title = cur.pdf ? '' : (dlg.dataset.lNoGen || '');
            $('[data-send-title]', dlg).textContent = b.dataset.title; busy.hide(); sending = false; render(); dlg.showModal();
        });
        file.addEventListener('change', function () { pick(file.files[0]); });
        ['dragenter', 'dragover'].forEach(function (n) { zone.addEventListener(n, function (e) { e.preventDefault(); zone.classList.add('border-signal-500', 'bg-signal-50/40'); }); });
        ['dragleave', 'drop'].forEach(function (n) { zone.addEventListener(n, function (e) { e.preventDefault(); zone.classList.remove('border-signal-500', 'bg-signal-50/40'); }); });
        zone.addEventListener('drop', function (e) { pick(e.dataTransfer.files[0]); });
        toggle.addEventListener('change', function () { if (toggle.checked) { picked = null; file.value = ''; } render(); });
        $('[data-send-clear]', dlg).addEventListener('click', function () { picked = null; file.value = ''; render(); });
        dlg.addEventListener('click', function (e) { if (e.target.closest('[data-send-close]') || (e.target === dlg && !sending)) dlg.close(); });
        dlg.addEventListener('cancel', function (e) { if (sending) e.preventDefault(); });
        dlg.addEventListener('close', function () { frame.removeAttribute('src'); });
        goBtn.addEventListener('click', function () {
            var gen = toggle.checked && cur.pdf;
            if (!gen && !picked) return;
            var fd = new FormData(); if (!gen) fd.append('pdf', picked);
            var m = document.querySelector('meta[name="csrf-token"]');
            sending = true; goBtn.disabled = true; busy.show(dlg.dataset.lSending, dlg.dataset.lSub);
            fetch(cur.url, { method: 'POST', credentials: 'same-origin', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': m ? m.content : '' } })
                .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'HTTP ' + r.status }; }); })
                .then(function (d) {
                    sending = false;
                    if (d.ok) busy.success(dlg.dataset.lSent, '', function () { dlg.close(); location.reload(); });   // the message comes back as a toast from the server flash
                    else { busy.hide(); goBtn.disabled = false; if (window.toast) toast(d.message || 'Failed', 'error'); }
                })
                .catch(function (x) { sending = false; busy.hide(); goBtn.disabled = false; if (window.toast) toast(x.message, 'error'); });
        });
    }

    // Regenerate one invoice: same job + animation as Generate, in a small centre window.
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-inet-regen]');
        if (!b) return;
        if (!confirm(b.dataset.confirmText)) return;
        var d = document.getElementById('inet-regen');
        if (!d) {
            d = document.createElement('dialog');
            d.id = 'inet-regen';
            d.className = 'm-auto h-72 w-[min(26rem,calc(100vw-2rem))] overflow-hidden rounded-xl bg-white p-0 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60';
            var tpl = document.querySelector('#inet-dialog [data-busy]');
            d.innerHTML = tpl ? tpl.outerHTML : '';
            document.body.appendChild(d);
            d.addEventListener('cancel', function (ev) { ev.preventDefault(); });
        }
        var L = (document.getElementById('inet-dialog') || {}).dataset || {};
        var busy = AcctBusy(d);
        d.showModal(); busy.show(L.lWorking || 'Generating…', L.lStepBuild || '');
        var base = document.querySelector('[data-inet-open]').dataset.url.replace(/candidates$/, '');
        postJson(base + 'generate', { items: [{ invoice: parseInt(b.dataset.invoice, 10), auto_pdf: b.dataset.auto === '1' }] })
            .then(function (r) { return followJob(base + 'job/' + r.job, 1, L); })
            .then(function (j) {
                if (j.errors && j.errors.length) { busy.hide(); d.close(); if (window.toast) toast(j.errors.join(' · '), 'error'); return; }
                busy.success(L.lDone || 'Generated', '', function () { d.close(); if (window.toast && toast.later) toast.later(b.dataset.invoice + ' ' + (L.lDone || 'Generated').toLowerCase(), 'success'); location.reload(); });
            })
            .catch(function (x) { busy.hide(); d.close(); if (window.toast) toast(x.message, 'error'); });
    });
})();
