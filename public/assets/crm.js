/*
 | CRM screens: small behaviours, no framework.
 |   [data-crm-show="field=a,b"]   show a block while the field (dropdown / radio / input) has one of these values
 |   [data-repeat]                 repeating rows (mobile numbers, products): [data-repeat-add], [data-repeat-remove],
 |                                 <template data-repeat-template> with __i__, [data-repeat-next] = next index, data-min = least rows
 |   [data-people]                 pick several people: [data-people-filter] hides rows that do not match, [data-people-count] counts the ticked ones
 |   [data-team]                   Team screen: [data-tick-all] / [data-tick] checkboxes, [data-ticked] counters, [data-needs-ticked] buttons wait for a tick, [data-confirm] asks first
 |   [data-product-row]            picking a registered product hides the free-text name and fills the unit price
 */
(function () {
    'use strict';

    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    function valueOf(name) {
        var els = $$('[name="' + name + '"]');
        if (!els.length) return null;
        if (els[0].type === 'radio') { var on = els.filter(function (e) { return e.checked; })[0]; return on ? on.value : ''; }
        return els[0].value;
    }

    function applyShow() {
        $$('[data-crm-show]').forEach(function (el) {
            var p = el.dataset.crmShow.split('='), v = valueOf(p[0]);
            if (v === null) return;
            el.hidden = p[1].split(',').indexOf(v) === -1;
        });
    }

    function addRow(box) {
        var next = $('[data-repeat-next]', box), n = parseInt(next.value, 10) || 0, tpl = $('[data-repeat-template]', box), rows = $('[data-repeat-rows]', box);
        var tmp = document.createElement('div');
        tmp.innerHTML = tpl.innerHTML.replace(/__i__/g, n);
        next.value = n + 1;
        var first;
        while (tmp.firstElementChild) { first = first || tmp.firstElementChild; rows.appendChild(tmp.firstElementChild); }
        var input = first && first.querySelector('input:not([type=hidden]):not([type=radio]), button[data-select-button]');
        if (input) input.focus();
        return first;
    }

    document.addEventListener('click', function (e) {
        var t;
        if ((t = e.target.closest('[data-repeat-add]'))) { addRow(t.closest('[data-repeat]')); return; }
        if ((t = e.target.closest('[data-repeat-remove]'))) {
            var row = t.closest('[data-repeat-row]'), box = row.closest('[data-repeat]'), hadMain = row.querySelector('input[type=radio]:checked');
            row.remove();
            var min = parseInt(box.dataset.min || '0', 10);
            if ($$('[data-repeat-row]', box).length < min) addRow(box);
            if (hadMain) { var radio = $('input[type=radio]', box); if (radio) radio.checked = true; }
        }
    });

    document.addEventListener('change', function (e) {
        applyShow();
        var input = e.target;
        if (input.matches && input.matches('[data-select-input]') && /^products\[[^\]]+\]\[product_id\]$/.test(input.name)) {
            var row = input.closest('[data-product-row]'), box = input.closest('[data-repeat]');
            var name = $('[data-product-name]', row), price = input.closest('[data-repeat-row]').querySelector('[data-product-price]');
            name.hidden = input.value !== '';
            if (input.value === '') { name.focus(); return; }
            var prices = {}; try { prices = JSON.parse(box.dataset.prices || '{}'); } catch (err) {}
            if (price && price.value === '' && prices[input.value] != null) price.value = prices[input.value];
        }
    });

    function peopleCount(box) { var c = $('[data-people-count]', box); if (c) c.textContent = $$('input:checked', box).length; }
    document.addEventListener('input', function (e) {
        var f = e.target.closest && e.target.closest('[data-people-filter]');
        if (!f) return;
        var q = f.value.trim().toLowerCase(), box = f.closest('[data-people]');
        $$('[data-people-row]', box).forEach(function (r) { r.hidden = q !== '' && r.dataset.q.indexOf(q) === -1; });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.closest && e.target.closest('[data-people-filter]')) e.preventDefault(); });
    document.addEventListener('change', function (e) { var box = e.target.closest && e.target.closest('[data-people]'); if (box) peopleCount(box); });
    function teamSync(form) {
        var ticks = $$('[data-tick]', form), n = ticks.filter(function (c) { return c.checked; }).length, all = $('[data-tick-all]', form);
        $$('[data-ticked]', form).forEach(function (x) { x.textContent = n; });
        $$('[data-needs-ticked]', form).forEach(function (b) { b.disabled = n === 0; });
        if (all) { all.checked = n > 0 && n === ticks.length; all.indeterminate = n > 0 && n < ticks.length; }
    }
    document.addEventListener('change', function (e) {
        var form = e.target.closest && e.target.closest('[data-team]'); if (!form) return;
        if (e.target.matches('[data-tick-all]')) $$('[data-tick]', form).forEach(function (c) { c.checked = e.target.checked; });
        teamSync(form);
    });
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('button[data-confirm], a[data-confirm]');   // a <form data-confirm> is asked by app.js
        if (b && !window.confirm(b.dataset.confirm)) e.preventDefault();
    });

    /* Import mapping: show the first values of the chosen file column next to each table column */
    function previews(form) {
        var samples = {}; try { samples = JSON.parse(form.dataset.samples || '{}'); } catch (e) {}
        $$('[data-map]', form).forEach(function (sel) {
            var out = sel.closest('tr').querySelector('[data-preview]'); if (!out) return;
            var v = samples[sel.value] || [];
            out.textContent = sel.value === '' ? '' : v.slice(0, 3).join(' · ');
            out.title = out.textContent;
        });
    }
    document.addEventListener('change', function (e) { var f = e.target.closest && e.target.closest('[data-map-form]'); if (f && e.target.matches('[data-map]')) previews(f); });

    function boot() { $$('[data-map-form]').forEach(previews); $$('[data-people]').forEach(peopleCount); $$('[data-team]').forEach(teamSync); }

    /* ------------------------------------------------ opportunity product lines [data-lines]
       "+ Add" opens a card; "Add" checks it and turns it into a line of hidden inputs products[i][…]. */
    function num(v) { v = String(v == null ? '' : v).replace(/,/g, '').trim(); return v === '' ? null : (isFinite(v) ? parseFloat(v) : NaN); }
    function money(n) { return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function linesTotal(box) {
        var t = 0;
        box.querySelectorAll('[data-line]').forEach(function (li) {
            var q = num(li.querySelector('[name$="[quantity]"]').value) || 0, p = num(li.querySelector('[name$="[unit_price]"]').value) || 0; t += q * p;
        });
        var out = box.querySelector('[data-lines-total]'); if (out) out.textContent = money(t);
    }
    function lineCardReset(card) {
        card.querySelectorAll('[data-l]').forEach(function (i) { i.value = i.dataset.l === 'quantity' ? '1' : ''; });
        var u = card.querySelector('[data-l-unit]');               // unit dropdown back to the first unit
        if (u) { u.querySelector('[data-select-input]').value = u.dataset.first; u.querySelector('[data-select-label]').textContent = u.dataset.first; }
        var pick = card.querySelector('[data-remote-select]');
        if (pick) { var h = pick.querySelector('[data-select-input]'), lb = pick.querySelector('[data-select-label]'); h.value = ''; lb.textContent = pick.dataset.placeholder || ''; lb.classList.add('text-graphite-400'); }
        var er = card.querySelector('[data-l-error]'); er.hidden = true;
    }
    document.addEventListener('click', function (e) {
        var box = e.target.closest && e.target.closest('[data-lines]'), t;
        // a product picked in the card's search: fill in name, number and price
        var opt = e.target.closest && e.target.closest('[role=option][data-extra]');
        if (opt) {
            var panel = opt.closest('[data-select-panel]'), sel = panel && panel._select, card = sel && sel.closest('[data-line-form]');
            if (card) {
                var x = {}; try { x = JSON.parse(opt.dataset.extra); } catch (err) { /* nothing to fill */ }
                card.querySelector('[data-l="erp_product_id"]').value = opt.dataset.value;
                card.querySelector('[data-l="name"]').value = x.name || opt.dataset.label || '';
                card.querySelector('[data-l="part_number"]').value = x.no || '';
                if (x.price != null) card.querySelector('[data-l="unit_price"]').value = x.price;
            }
            return;
        }
        if (!box) return;
        if ((t = e.target.closest('[data-line-open]'))) {
            var c = box.querySelector('[data-line-form="' + t.dataset.lineOpen + '"]'); lineCardReset(c); c.hidden = false;
            var first = c.querySelector('[data-select-button], [data-l="name"]'); if (first) first.focus();
        } else if ((t = e.target.closest('[data-line-cancel]'))) {
            t.closest('[data-line-form]').hidden = true;
        } else if ((t = e.target.closest('[data-line-add]'))) {
            var card2 = t.closest('[data-line-form]'), kind = card2.dataset.lineForm, v = {}, er = card2.querySelector('[data-l-error]');
            card2.querySelectorAll('[data-l]').forEach(function (i) { v[i.dataset.l] = i.value.trim(); });
            var uu = card2.querySelector('[data-l-unit] [data-select-input]'); if (uu) v.unit = uu.value;
            var q = num(v.quantity), pr = num(v.unit_price), msg = '';
            if (!v.name) msg = box.dataset.msgName; else if (q === null || isNaN(q) || q <= 0) msg = box.dataset.msgQty; else if (isNaN(pr)) msg = box.dataset.msgPrice;
            if (msg) { er.textContent = msg; er.hidden = false; return; }
            var i = parseInt(box.dataset.counter || '0', 10); box.dataset.counter = i + 1;
            var tpl = box.querySelector('[data-line-template]').innerHTML.replace(/__i__/g, i), wrap = document.createElement('ul'); wrap.innerHTML = tpl;
            var li = wrap.firstElementChild;
            v.kind = kind; v.quantity = String(q); v.unit_price = pr === null ? '' : String(pr);
            ['kind', 'erp_product_id', 'product_id', 'name', 'part_number', 'quantity', 'unit', 'unit_price'].forEach(function (k) { li.querySelector('[name$="[' + k + ']"]').value = v[k] || ''; });
            li.querySelector('[data-l-name]').textContent = v.name;
            li.querySelector('[data-l-sub]').textContent = [v.part_number, q + ' ' + (v.unit || ''), pr === null ? '' : money(pr)].filter(Boolean).join(' · ');
            li.querySelector('[data-l-total]').textContent = pr === null ? '' : money(q * pr);
            box.querySelector('[data-line-list="' + kind + '"]').appendChild(li);
            box.querySelector('[data-line-empty="' + kind + '"]').hidden = true;
            card2.hidden = true; linesTotal(box);
            var form = box.closest('form'); if (form) { form.dataset.dirty = '1'; form.dispatchEvent(new Event('change', { bubbles: true })); }
        } else if ((t = e.target.closest('[data-line-remove]'))) {
            var row = t.closest('[data-line]'), list = row.parentNode; row.remove();
            var k2 = list.dataset.lineList; box.querySelector('[data-line-empty="' + k2 + '"]').hidden = list.children.length > 0;
            linesTotal(box);
        }
    });

    /* ------------------------------------------------ next steps typed in the form [data-steps] */
    document.addEventListener('click', function (e) {
        var box = e.target.closest && e.target.closest('[data-steps]'), t; if (!box) return;
        var card = box.querySelector('[data-step-form]');
        if (e.target.closest('[data-step-open]')) { card.querySelectorAll('[data-s]').forEach(function (i) { i.value = ''; }); card.querySelector('[data-s-error]').hidden = true; card.hidden = false; card.querySelector('[data-s="title"]').focus(); }
        else if (e.target.closest('[data-step-cancel]')) card.hidden = true;
        else if (e.target.closest('[data-step-add]')) {
            var v = {}; card.querySelectorAll('[data-s]').forEach(function (i) { v[i.dataset.s] = i.value.trim(); });
            if (!v.title) { var er = card.querySelector('[data-s-error]'); er.textContent = box.dataset.msgTitle; er.hidden = false; return; }
            var i = parseInt(box.dataset.counter || '0', 10); box.dataset.counter = i + 1;
            var wrap = document.createElement('ul'); wrap.innerHTML = box.querySelector('[data-step-template]').innerHTML.replace(/__i__/g, i);
            var li = wrap.firstElementChild;
            ['title', 'due_at', 'note'].forEach(function (k) { li.querySelector('[name$="[' + k + ']"]').value = v[k]; });
            li.querySelector('[data-s-title]').textContent = v.title;
            var d = v.due_at ? new Date(v.due_at + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '';
            li.querySelector('[data-s-sub]').textContent = [d, v.note].filter(Boolean).join(' · ');
            box.querySelector('[data-step-list]').appendChild(li); box.querySelector('[data-step-empty]').hidden = true; card.hidden = true;
            var form = box.closest('form'); if (form) form.dataset.dirty = '1';
        } else if ((t = e.target.closest('[data-step-remove]'))) {
            t.closest('[data-step-line]').remove();
            box.querySelector('[data-step-empty]').hidden = box.querySelectorAll('[data-step-line]').length > 0 || box.querySelectorAll('li').length > 0;
        }
    });

    /* ------------------------------------------------ files dropped or chosen [data-dropfiles] (kept across several picks) */
    function filesShow(box) {
        var inp = box.querySelector('[data-files-input]'), list = box.querySelector('[data-files-list]'), have = parseInt(inp.dataset.have || '0', 10);
        list.querySelectorAll('[data-new-file]').forEach(function (n) { n.remove(); });
        Array.prototype.forEach.call(inp.files, function (f, i) {
            var li = document.createElement('li'); li.setAttribute('data-new-file', i);
            li.className = 'flex items-center gap-2 rounded-md bg-white px-3 py-2 text-sm ring-1 ring-graphite-900/10';
            li.innerHTML = '<span class="min-w-0 flex-1 truncate"></span><span class="text-xs text-steel"></span><button type="button" class="btn-ghost px-2 py-1 hover:text-signal-700" data-file-x aria-label="Remove">&times;</button>';
            li.children[0].textContent = f.name; li.children[1].textContent = Math.max(1, Math.round(f.size / 1024)) + ' KB';
            list.appendChild(li);
        });
        box.querySelector('[data-count]').textContent = have + inp.files.length;
    }
    function filesSet(box, files) {
        var inp = box.querySelector('[data-files-input]'), max = parseInt(box.dataset.max || '5', 10), dt = new DataTransfer();
        files.forEach(function (f) { if (dt.files.length < max) dt.items.add(f); });
        if (files.length > max && window.toast) window.toast(box.dataset.msgFull, 'warning');
        inp.files = dt.files; filesShow(box);
    }
    document.addEventListener('change', function (e) {
        var inp = e.target.closest && e.target.closest('[data-files-input]'); if (!inp) return;
        var box = inp.closest('[data-dropfiles]'); if (inp._skip) { inp._skip = false; return; }
        filesSet(box, (inp._kept || []).concat(Array.prototype.slice.call(inp.files)));
        inp._kept = Array.prototype.slice.call(inp.files);
    });
    document.addEventListener('click', function (e) {
        var x = e.target.closest && e.target.closest('[data-file-x]'); if (!x) return;
        var box = x.closest('[data-dropfiles]'), inp = box.querySelector('[data-files-input]'), k = parseInt(x.closest('[data-new-file]').getAttribute('data-new-file'), 10);
        var rest = Array.prototype.filter.call(inp.files, function (f, i) { return i !== k; }); filesSet(box, rest); inp._kept = rest;
    });
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (ev) {
        document.addEventListener(ev, function (e) {
            var z = e.target.closest && e.target.closest('[data-dropfiles] [data-drop]'); if (!z) return;
            e.preventDefault(); z.classList.toggle('!border-signal-600', ev === 'dragenter' || ev === 'dragover');
            if (ev === 'drop' && e.dataTransfer && e.dataTransfer.files.length) {
                var box = z.closest('[data-dropfiles]'), inp = box.querySelector('[data-files-input]');
                var all = (inp._kept || []).concat(Array.prototype.slice.call(e.dataTransfer.files)); filesSet(box, all); inp._kept = Array.prototype.slice.call(inp.files);
            }
        });
    });

    window.AcmeCrmApply = function () { applyShow(); boot(); };
    document.addEventListener('DOMContentLoaded', window.AcmeCrmApply);
    window.AcmeCrmApply();
})();
