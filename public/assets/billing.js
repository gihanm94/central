/* Billing notes: the generate page (4 steps + summary) and the PDF window of the list. */
(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    var money = function (n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var csrf = function () { var m = $('meta[name="csrf-token"]'); return m ? m.content : ''; };

    /* ------------------------------------------------------------ PDF window (list) */
    var bv = $('#bill-viewer');
    if (bv) {
        var frame = $('[data-bv-frame]', bv), pct = 100, base = '';
        var load = function () { frame.src = base + (base.indexOf('?') < 0 ? '?' : '&') + '_=' + Date.now() + '#zoom=' + pct + '&toolbar=0'; $('[data-bv-pct]', bv).textContent = pct + '%'; };
        document.addEventListener('click', function (e) {
            var a = e.target.closest('a[href$="/pdf"]');
            if (!a || !/\/accounting\/billing\/\d+\/pdf$/.test(a.getAttribute('href'))) return;
            e.preventDefault();
            base = a.getAttribute('href'); pct = 100;
            $('[data-bv-title]', bv).textContent = a.dataset.title || a.textContent.trim() || 'PDF';
            $('[data-bv-download]', bv).href = base + '?download=1';
            load(); bv.showModal();
        });
        bv.addEventListener('click', function (e) {
            var z = e.target.closest('[data-bv-zoom]');
            if (z) { pct = Math.max(50, Math.min(300, pct + parseInt(z.dataset.bvZoom, 10))); load(); return; }
            if (e.target.closest('[data-bv-reload]')) { load(); return; }
            if (e.target.closest('[data-bv-print]')) { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (x) { window.open(base, '_blank'); } return; }
            if (e.target.closest('[data-bv-close]') || e.target === bv) bv.close();
        });
        bv.addEventListener('close', function () { frame.removeAttribute('src'); });
    }

    /* ------------------------------------------------------------ generate page */
    var page = $('#bill-page');
    if (!page) return;
    var st = { lang: 'th', cust: null, address: '', invoices: [], picked: {}, sameRemind: true }, L = page.dataset;
    var custBtn = $('[data-cust-btn]', page), pop = $('[data-cust-pop]', page), q = $('[data-cust-q]', page), list = $('[data-cust-list]', page), timer;
    var busy = typeof AcctBusy === 'function' ? AcctBusy(page) : null;

    function fetchJson(u) {
        // tolerate stray PHP notices before the JSON, and surface real failures instead of silently doing nothing
        return fetch(u, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) {
            return r.text().then(function (t) {
                var i = t.indexOf('{'); if (i > 0) t = t.slice(i);
                try { return JSON.parse(t); } catch (e) { throw new Error('HTTP ' + r.status); }
            });
        });
    }
    function loadCustomers() {
        fetchJson(L.customers + '?q=' + encodeURIComponent(q.value.trim())).then(function (d) {
            list.innerHTML = d.rows.map(function (c) {
                return '<li role="option" data-id="' + c.id + '" class="flex cursor-pointer items-center justify-between gap-3 rounded-md px-3 py-2 hover:bg-mist"><span class="min-w-0"><span class="block truncate text-sm font-medium">' + esc(c.name) + '</span><span class="block text-xs text-steel">' + esc(c.code) + '</span></span>' +
                    '<span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium ' + (c.invoices > 0 ? 'bg-sky-50 text-sky-800' : 'bg-mist text-steel') + '">' + c.invoices + ' ' + esc(L.lInv) + '</span></li>';
            }).join('') || '<li class="px-3 py-4 text-center text-sm text-steel">' + esc(L.lNoCust || 'No customers found. Sync Customers from the ERP first.') + '</li>';
        }).catch(function (e) { list.innerHTML = '<li class="px-3 py-4 text-center text-sm text-signal-700">' + esc(e.message) + '</li>'; });
    }
    custBtn.addEventListener('click', function () { pop.hidden = !pop.hidden; if (!pop.hidden) { q.value = ''; loadCustomers(); q.focus(); } });
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(loadCustomers, 250); });
    document.addEventListener('click', function (e) { if (!pop.hidden && !e.target.closest('[data-cust-pop]') && !e.target.closest('[data-cust-btn]')) pop.hidden = true; });
    list.addEventListener('click', function (e) {
        var li = e.target.closest('li[data-id]'); if (!li) return;
        pop.hidden = true;
        fetchJson(L.invoices + '?customer=' + li.dataset.id).then(function (d) {
            st.cust = d.customer; st.address = d.customer.address || ''; st.invoices = d.rows; st.picked = {};
            $('[data-cust-name]', page).textContent = d.customer.name; $('[data-cust-name]', page).classList.remove('text-steel');
            $('[data-cust-code]', page).textContent = d.customer.code;
            $('[data-addr-text]', page).textContent = st.address || '—';
            var custom = $('input[name=addr][value=custom]', page); if (!st.address) { custom.checked = true; $('[data-addr-custom]', page).hidden = false; } else { $('input[name=addr][value=customer]', page).checked = true; $('[data-addr-custom]', page).hidden = true; }
            renderInvoices(); update();
        }).catch(function (e) { window.toast ? window.toast(e.message, 'error') : alert(e.message); });
    });

    function renderInvoices() {
        var empty = $('[data-inv-empty]', page), box = $('[data-inv-box]', page), body = $('[data-inv-rows]', page);
        if (!st.invoices.length) { empty.hidden = false; empty.textContent = L.lNone; box.hidden = true; return; }
        empty.hidden = true; box.hidden = false;
        body.innerHTML = st.invoices.map(function (r) {
            return '<tr class="hover:bg-mist/50"><td class="px-3 py-2"><input type="checkbox" data-inv="' + r.invoice_number + '" class="size-4 accent-signal-600"></td><td class="px-3 py-2 font-medium">' + r.invoice_number + (r.is_credit ? ' <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">CN</span>' : '') + '</td>' +
                '<td class="px-3 py-2">' + esc(r.order_no) + '</td><td class="px-3 py-2 tabular-nums text-steel">' + esc(r.invoice_date || '') + '</td><td class="px-3 py-2 tabular-nums text-steel">' + esc(r.due_date || '') + '</td><td class="px-3 py-2 text-right tabular-nums">' + (r.is_credit ? '-' : '') + money(r.amount) + '</td></tr>';
        }).join('');
    }
    page.addEventListener('change', function (e) {
        var c = e.target.closest('[data-inv]');
        if (c) { if (c.checked) st.picked[c.dataset.inv] = true; else delete st.picked[c.dataset.inv]; update(); return; }
        if (e.target.matches('[data-inv-all]')) { $$('[data-inv]', page).forEach(function (b) { b.checked = e.target.checked; if (b.checked) st.picked[b.dataset.inv] = true; else delete st.picked[b.dataset.inv]; }); update(); return; }
        if (e.target.name === 'addr') { $('[data-addr-custom]', page).hidden = e.target.value !== 'custom'; update(); return; }
        if (e.target.matches('[data-remind-same]')) { st.sameRemind = e.target.checked; $('[data-remind-date]', page).disabled = st.sameRemind; update(); return; }
        if (e.target.matches('[data-override-on]')) { var o = $('[data-override]', page); o.hidden = !e.target.checked; if (!e.target.checked) o.value = ''; return; }
        update();
    });
    page.addEventListener('input', function (e) { if (e.target.matches('[data-addr-custom], [data-bill-date], [data-remind-date]')) update(); });
    $$('[data-lang]', page).forEach(function (b) { b.addEventListener('click', function () {
        st.lang = b.dataset.lang;
        $$('[data-lang]', page).forEach(function (x) { var on = x === b; x.classList.toggle('bg-white', on); x.classList.toggle('shadow-sm', on); x.classList.toggle('text-steel', !on); });
    }); });

    function addr() { var custom = ($('input[name=addr]:checked', page) || {}).value === 'custom'; return custom ? $('[data-addr-custom]', page).value.trim() : st.address; }
    function update() {
        var picked = st.invoices.filter(function (r) { return st.picked[r.invoice_number]; });
        var bd = $('[data-bill-date]', page).value, rd = st.sameRemind ? bd : $('[data-remind-date]', page).value;
        if (st.sameRemind) $('[data-remind-date]', page).value = bd;
        var a = addr();
        var ok = [!!st.cust, picked.length > 0, a.length > 0, !!bd];
        $$('[data-step]', page).forEach(function (s, i) {
            var dot = $('[data-dot]', s); dot.className = 'flex size-9 items-center justify-center rounded-full text-sm font-semibold ' + (ok[i] ? 'bg-emerald-500 text-white' : 'bg-graphite-900/8 text-steel');
            dot.innerHTML = ok[i] ? '<svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>' : (i + 1);
            s.classList.toggle('ring-emerald-200', ok[i]);
        });
        $$('[data-prog]', page).forEach(function (p, i) { p.className = 'h-1 rounded-full ' + (ok[i] ? 'bg-signal-600' : 'bg-graphite-900/10'); });
        var n = ok.filter(Boolean).length; $('[data-prog-n]', page).textContent = n;
        $('[data-sum-cust]', page).textContent = st.cust ? st.cust.code + ' · ' + st.cust.name : '—';
        $('[data-sum-inv]', page).textContent = picked.length ? picked.length + ' ' + L.lItems : '—';
        $('[data-sum-chips]', page).innerHTML = picked.slice(0, 12).map(function (r) { return '<span class="rounded bg-signal-50 px-1.5 py-0.5 text-[11px] font-medium text-signal-800">' + r.invoice_number + '</span>'; }).join('') + (picked.length > 12 ? '<span class="text-xs text-steel">+' + (picked.length - 12) + '</span>' : '');
        $('[data-sum-addr]', page).textContent = a || '—';
        $('[data-sum-date]', page).textContent = bd || '—'; $('[data-sum-remind]', page).textContent = rd || '—'; $('[data-sum-same]', page).textContent = st.sameRemind ? L.lSame : '';
        var total = picked.reduce(function (s, r) { return s + (r.is_credit ? -r.amount : r.amount); }, 0);
        $('[data-sum-total]', page).textContent = picked.length ? money(total) + ' ' + (picked[0].currency || '') : '—';
        $('[data-generate]', page).disabled = n < 4;
    }
    $('[data-generate]', page).addEventListener('click', function () {
        var picked = Object.keys(st.picked).map(Number), bd = $('[data-bill-date]', page).value;
        var body = { customer_id: st.cust.id, invoices: picked, address: addr(), billing_date: bd, remind_date: st.sameRemind ? bd : $('[data-remind-date]', page).value, lang: st.lang, override: ($('[data-override]', page) || {}).value || '' };
        if (busy) busy.show(L.lWorking, '');
        fetch(L.store, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body) })
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message || 'Failed'); return d; }); })
            .then(function (d) { if (busy) busy.success(L.lDone, '', function () { location.href = d.url; }); else location.href = d.url; })
            .catch(function (x) { if (busy) busy.hide(); if (window.toast) toast(x.message, 'error'); });
    });
    update();
})();
