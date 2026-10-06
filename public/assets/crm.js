/*
 | CRM screens: small behaviours, no framework.
 |   [data-crm-show="field=a,b"]   show a block while the field (dropdown / radio / input) has one of these values
 |   [data-repeat]                 repeating rows (mobile numbers, products): [data-repeat-add], [data-repeat-remove],
 |                                 <template data-repeat-template> with __i__, [data-repeat-next] = next index, data-min = least rows
 |   [data-people]                 pick several people: [data-people-filter] hides rows that do not match, [data-people-count] counts the ticked ones
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
    function boot() { $$('[data-people]').forEach(peopleCount); }

    window.AcmeCrmApply = function () { applyShow(); boot(); };
    document.addEventListener('DOMContentLoaded', window.AcmeCrmApply);
    window.AcmeCrmApply();
})();
