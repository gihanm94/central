/*
 | Accounting screens.
 |   [data-inet-open]   opens the "Select Invoices to Generate" dialog: search (3+ letters), Invoice / Credit note tabs,
 |                      10 per page, a tick stays when you page on, "Auto PDF" can be set on each ticked row, Generate posts them.
 */
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
                '<td class="px-3 py-2.5">' + esc(r.order_no) + '</td><td class="px-3 py-2.5">' + esc(r.delivery_no) + '</td><td class="px-3 py-2.5">' + esc(r.vat_no) + '</td><td class="px-3 py-2.5 text-steel">' + esc(r.remark) + '</td>';
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
    go.addEventListener('click', function () {
        go.disabled = true; err.hidden = true;
        var items = Object.keys(picked).map(function (no) { return { invoice: parseInt(no, 10), auto_pdf: picked[no].auto }; });
        fetch(url.replace(/candidates$/, 'generate'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ items: items }) })
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message || 'Failed'); return d; }); })
            .then(function (d) {
                if (d.errors && d.errors.length) { err.hidden = false; err.textContent = d.errors.join(' · '); dlg.addEventListener('close', function () { location.reload(); }, { once: true }); go.disabled = true; return; }
                location.reload();
            })
            .catch(function (x) { err.hidden = false; err.textContent = x.message; go.disabled = false; });
    });
    tabs();
})();
