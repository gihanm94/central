/*
 | Tailwind date and time pickers: the browser's own date / time inputs are never shown.
 | Every <input type="date | datetime-local | time | month"> is enhanced on load and whenever one is added later.
 | The input stays in the form as the value holder (hidden, same name, id and events), a button shows the value and
 | opens the picker. Values keep the native formats: 2026-10-19, 2026-10-19T14:30, 14:30, 2026-10.
 | Honours min, max, step (time, in seconds), required and disabled; setting input.value from a script updates the
 | button too. Keyboard: arrows move the day, PageUp / PageDown the month, Enter picks, Escape closes.
 | Opt out with data-native.
 */
(function () {
    'use strict';

    var KINDS = { 'date': 1, 'datetime-local': 1, 'time': 1, 'month': 1 };
    var th = (document.documentElement.lang || '').slice(0, 2) === 'th';
    var LOC = (th ? 'th-TH' : 'en-GB') + '-u-ca-gregory';
    var T = th
        ? { today: 'วันนี้', now: 'ตอนนี้', thisMonth: 'เดือนนี้', clear: 'ล้าง', done: 'ตกลง', date: 'เลือกวันที่', time: 'เลือกเวลา', month: 'เลือกเดือน', hour: 'ชั่วโมง', minute: 'นาที', required: 'กรุณาเลือก' }
        : { today: 'Today', now: 'Now', thisMonth: 'This month', clear: 'Clear', done: 'Done', date: 'Select date', time: 'Select time', month: 'Select month', hour: 'Hour', minute: 'Minute', required: 'Please choose a value' };
    var VALUE = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    var ICON = {
        date: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="size-4 shrink-0 text-graphite-400" aria-hidden="true"><rect x="3" y="4.5" width="14" height="12.5" rx="2"/><path d="M3 8.5h14M7 3v3M13 3v3"/></svg>',
        time: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="size-4 shrink-0 text-graphite-400" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 2"/></svg>'
    };
    var NAV = 'flex size-8 items-center justify-center rounded-md text-graphite-600 hover:bg-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-600';
    var CELL = 'flex items-center justify-center rounded-md text-sm tabular-nums focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-600 focus-visible:ring-offset-1';

    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var ymd = function (y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); };          // m is 0-based
    var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var fmt = function (o, d) { return new Intl.DateTimeFormat(LOC, o).format(d); };

    /** '2026-10-19T14:30' → {y, m, d, h, i}; missing parts are null. */
    function parse(kind, v) {
        var p = { y: null, m: null, d: null, h: null, i: null }, r;
        if (!v) return p;
        if ((r = /^(\d{4})-(\d{2})(?:-(\d{2}))?/.exec(v))) { p.y = +r[1]; p.m = +r[2] - 1; p.d = r[3] ? +r[3] : null; }
        if ((r = /(?:^|T|\s)(\d{2}):(\d{2})/.exec(v))) { p.h = +r[1]; p.i = +r[2]; }
        return p;
    }

    function label(input) {
        var kind = input._dp.kind, v = VALUE.get.call(input), p = parse(kind, v);
        if (!v || (kind !== 'time' && p.y === null) || (kind === 'time' && p.h === null)) return '';
        if (kind === 'time') return pad(p.h) + ':' + pad(p.i);
        if (kind === 'month') return fmt({ month: 'long', year: 'numeric' }, new Date(p.y, p.m, 1));
        var s = fmt({ day: 'numeric', month: 'short', year: 'numeric' }, new Date(p.y, p.m, p.d || 1));
        return kind === 'datetime-local' && p.h !== null ? s + ', ' + pad(p.h) + ':' + pad(p.i) : s;
    }

    /* ------------------------------------------------------------ enhance */

    function enhance(input) {
        if (input._dp || input.hasAttribute('data-native')) return;
        var kind = input.getAttribute('data-dp') || input.getAttribute('type');
        if (!KINDS[kind]) return;
        var next = input.nextElementSibling;
        if (next && next.hasAttribute('data-dp-btn') && !next._input) next.remove();   // a copied row brought a dead button

        var v = input.value;
        input.type = 'hidden';
        input.setAttribute('data-dp', kind);
        VALUE.set.call(input, v);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('data-dp-btn', '');
        btn.setAttribute('aria-haspopup', 'dialog');
        btn.setAttribute('aria-expanded', 'false');
        btn.className = (input.className.replace(/\bhidden\b/g, '') || 'input') + ' flex items-center justify-between gap-2 text-left disabled:cursor-not-allowed disabled:opacity-60';
        btn.innerHTML = '<span data-dp-label class="min-w-0 truncate"></span>' + (kind === 'time' ? ICON.time : ICON.date);
        btn._input = input;
        input._dp = { kind: kind, btn: btn };
        input.insertAdjacentElement('afterend', btn);

        // input.value = '…' from other scripts (billing, filters) shows on the button at once.
        Object.defineProperty(input, 'value', {
            configurable: true,
            get: function () { return VALUE.get.call(this); },
            set: function (x) { VALUE.set.call(this, x); render(this); }
        });
        new MutationObserver(function () { render(input); }).observe(input, { attributes: true, attributeFilter: ['disabled', 'required'] });
        render(input);
    }

    function render(input) {
        var dp = input._dp, text = label(input), lab = input.id && document.querySelector('label[for="' + CSS.escape(input.id) + '"]');
        var name = (lab ? lab.textContent : input.getAttribute('aria-label') || '').trim();
        var span = dp.btn.querySelector('[data-dp-label]');
        span.textContent = text || T[dp.kind === 'datetime-local' ? 'date' : dp.kind];
        span.classList.toggle('text-graphite-400', !text);
        dp.btn.disabled = input.disabled;
        dp.btn.setAttribute('aria-label', (name ? name + ': ' : '') + (text || T[dp.kind === 'datetime-local' ? 'date' : dp.kind]));
        if (text) { dp.btn.removeAttribute('aria-invalid'); dp.btn.classList.remove('!ring-2', '!ring-signal-600'); }
    }

    function scan(root) {
        if (root.nodeType !== 1) return;
        if (root.matches('input')) { enhance(root); return; }
        Array.prototype.forEach.call(root.querySelectorAll('input[type=date],input[type=datetime-local],input[type=time],input[type=month],input[data-dp]'), enhance);
    }

    /* -------------------------------------------------------------- panel */

    var panel = null, cur = null;

    function commit(v) {
        var input = cur.input;
        if (VALUE.get.call(input) === v) return;
        VALUE.set.call(input, v);
        render(input);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function bounds(input) {
        return { min: (input.getAttribute('min') || '').slice(0, 10), max: (input.getAttribute('max') || '').slice(0, 10) };
    }

    function openFor(input) {
        if (input.disabled) return;
        if (cur && cur.input === input) { close(true); return; }
        close();
        if (!panel) {
            panel = document.createElement('div');
            panel.setAttribute('role', 'dialog');
            panel.hidden = true;
            panel.className = 'fixed z-[70] rounded-lg bg-white p-3 shadow-xl ring-1 ring-graphite-900/10';
            panel.addEventListener('click', onPanelClick);
            panel.addEventListener('keydown', onPanelKey);
            document.body.appendChild(panel);
        }
        var kind = input._dp.kind, p = parse(kind, VALUE.get.call(input)), now = new Date();
        cur = {
            input: input, kind: kind,
            view: kind === 'month' ? 'months' : 'days',
            y: p.y !== null ? p.y : now.getFullYear(), m: p.m !== null ? p.m : now.getMonth()
        };
        panel.setAttribute('aria-label', input._dp.btn.getAttribute('aria-label'));
        input._dp.btn.setAttribute('aria-expanded', 'true');
        draw();
        place();
        var f = panel.querySelector('[data-dp-focus]') || panel.querySelector('button:not(:disabled)');
        if (f) f.focus({ preventScroll: true });
        panel.querySelectorAll('[data-dp-col] [aria-selected=true]').forEach(function (o) { o.parentNode.scrollTop = o.offsetTop - 64; });
    }

    function close(focusBack) {
        if (!cur) return;
        panel.hidden = true;
        cur.input._dp.btn.setAttribute('aria-expanded', 'false');
        if (focusBack) cur.input._dp.btn.focus();
        cur = null;
    }

    function place() {
        var r = cur.input._dp.btn.getBoundingClientRect(), vw = window.innerWidth, vh = window.innerHeight;
        panel.hidden = false;
        var pw = panel.offsetWidth, ph = panel.offsetHeight, top = r.bottom + 6;
        if (top + ph > vh - 8 && r.top - ph - 6 > 8) top = r.top - ph - 6;                 // flip up
        panel.style.left = Math.max(8, Math.min(r.left, vw - pw - 8)) + 'px';
        panel.style.top = Math.max(8, Math.min(top, vh - ph - 8)) + 'px';
    }

    function draw() {
        var kind = cur.kind, input = cur.input, h = '';
        panel.style.width = kind === 'time' ? '13rem' : (kind === 'datetime-local' ? '20rem' : '18rem');
        if (kind !== 'time') h += cur.view === 'days' ? days(input) : (cur.view === 'months' ? months(input) : years(input));
        if (kind === 'time' || kind === 'datetime-local') h += clock(input);
        var today = kind === 'time' ? T.now : (kind === 'month' ? T.thisMonth : T.today);
        h += '<div class="mt-3 flex items-center justify-between gap-2 border-t border-graphite-900/8 pt-3 text-sm">'
            + '<div class="flex gap-1"><button type="button" data-dp-today class="rounded-md px-2 py-1 font-medium text-signal-700 hover:bg-mist">' + today + '</button>'
            + (input.required ? '' : '<button type="button" data-dp-clear class="rounded-md px-2 py-1 text-steel hover:bg-mist">' + T.clear + '</button>') + '</div>'
            + '<button type="button" data-dp-done class="rounded-md bg-graphite-900 px-3 py-1 font-medium text-white hover:bg-graphite-700">' + T.done + '</button></div>';
        panel.innerHTML = h;
    }

    function head(title, view) {
        return '<div class="mb-2 flex items-center justify-between gap-1">'
            + '<button type="button" data-dp-nav="-1" class="' + NAV + '" aria-label="Previous">&#8249;</button>'
            + '<button type="button" data-dp-view="' + view + '" class="rounded-md px-2 py-1 font-display text-lg font-semibold leading-none hover:bg-mist">' + esc(title) + '</button>'
            + '<button type="button" data-dp-nav="1" class="' + NAV + '" aria-label="Next">&#8250;</button></div>';
    }

    function days(input) {
        var b = bounds(input), sel = parse(cur.kind, VALUE.get.call(input)), now = new Date();
        var todayIso = ymd(now.getFullYear(), now.getMonth(), now.getDate());
        var selIso = sel.y !== null && sel.d !== null ? ymd(sel.y, sel.m, sel.d) : '';
        var first = new Date(cur.y, cur.m, 1), shift = (first.getDay() + 6) % 7;            // weeks start on Monday
        var h = head(fmt({ month: 'long', year: 'numeric' }, first), 'months') + '<div class="grid grid-cols-7 gap-0.5 text-center">';
        for (var w = 0; w < 7; w++) h += '<div class="pb-1 text-xs font-medium text-steel">' + esc(fmt({ weekday: 'narrow' }, new Date(2024, 0, 1 + w))) + '</div>';
        var focusIso = selIso && selIso.slice(0, 7) === ymd(cur.y, cur.m, 1).slice(0, 7) ? selIso : (todayIso.slice(0, 7) === ymd(cur.y, cur.m, 1).slice(0, 7) ? todayIso : ymd(cur.y, cur.m, 1));
        for (var i = 0; i < 42; i++) {
            var d = new Date(cur.y, cur.m, 1 - shift + i), iso = ymd(d.getFullYear(), d.getMonth(), d.getDate());
            var off = (b.min && iso < b.min) || (b.max && iso > b.max), out = d.getMonth() !== cur.m, cls = CELL + ' h-9 ';
            if (iso === selIso) cls += 'bg-signal-600 font-semibold text-white hover:bg-signal-700';
            else if (off) cls += 'cursor-not-allowed text-graphite-300';
            else if (iso === todayIso) cls += 'font-semibold text-signal-700 ring-1 ring-inset ring-signal-600/50 hover:bg-mist';
            else cls += (out ? 'text-graphite-400' : 'text-graphite-900') + ' hover:bg-mist';
            h += '<button type="button" data-dp-day="' + iso + '" class="' + cls + '"' + (off ? ' disabled' : '') + (iso === focusIso ? ' data-dp-focus' : '')
                + ' tabindex="' + (iso === focusIso ? '0' : '-1') + '" aria-label="' + esc(fmt({ dateStyle: 'full' }, d)) + '"' + (iso === selIso ? ' aria-pressed="true"' : '') + '>' + d.getDate() + '</button>';
        }
        return h + '</div>';
    }

    function months(input) {
        var b = bounds(input), sel = parse(cur.kind, VALUE.get.call(input)), now = new Date();
        var h = head(String(cur.y), 'years') + '<div class="grid grid-cols-3 gap-1">';
        for (var m = 0; m < 12; m++) {
            var key = ymd(cur.y, m, 1).slice(0, 7), on = sel.y === cur.y && sel.m === m;
            var off = (b.min && key < b.min.slice(0, 7)) || (b.max && key > b.max.slice(0, 7));
            var cls = CELL + ' h-10 ' + (on ? 'bg-signal-600 font-semibold text-white hover:bg-signal-700' : off ? 'cursor-not-allowed text-graphite-300'
                : (now.getFullYear() === cur.y && now.getMonth() === m ? 'font-semibold text-signal-700 ' : 'text-graphite-900 ') + 'hover:bg-mist');
            h += '<button type="button" data-dp-month="' + m + '" class="' + cls + '"' + (off ? ' disabled' : '') + (on ? ' data-dp-focus' : '') + '>' + esc(fmt({ month: 'short' }, new Date(cur.y, m, 1))) + '</button>';
        }
        return h + '</div>';
    }

    function years(input) {
        var sel = parse(cur.kind, VALUE.get.call(input)), start = cur.y - (cur.y % 12), now = new Date().getFullYear();
        var h = head(start + ' – ' + (start + 11), 'years') + '<div class="grid grid-cols-3 gap-1">';
        for (var y = start; y < start + 12; y++) {
            var on = sel.y === y;
            h += '<button type="button" data-dp-year="' + y + '" class="' + CELL + ' h-10 ' + (on ? 'bg-signal-600 font-semibold text-white hover:bg-signal-700' : (y === now ? 'font-semibold text-signal-700 ' : 'text-graphite-900 ') + 'hover:bg-mist') + '"' + (on ? ' data-dp-focus' : '') + '>' + y + '</button>';
        }
        return h + '</div>';
    }

    function clock(input) {
        var sel = parse(cur.kind, VALUE.get.call(input));
        var step = Math.max(1, Math.round((parseInt(input.getAttribute('step'), 10) || 300) / 60)), mins = [];
        for (var i = 0; i < 60; i += step) mins.push(i);
        if (sel.i !== null && mins.indexOf(sel.i) < 0) { mins.push(sel.i); mins.sort(function (a, b) { return a - b; }); }
        var col = function (name, list, on, attr) {
            var h = '<div class="min-w-0 flex-1"><p class="mb-1 text-center text-xs font-medium text-steel">' + name + '</p><div data-dp-col class="relative h-40 overflow-y-auto rounded-md bg-mist/60 p-1">';
            list.forEach(function (n) {
                h += '<button type="button" ' + attr + '="' + n + '" aria-selected="' + (n === on) + '" class="' + CELL + ' h-8 w-full '
                    + (n === on ? 'bg-signal-600 font-semibold text-white' : 'text-graphite-900 hover:bg-white') + '">' + pad(n) + '</button>';
            });
            return h + '</div></div>';
        };
        var hours = []; for (var x = 0; x < 24; x++) hours.push(x);
        return '<div class="' + (cur.kind === 'datetime-local' ? 'mt-3 border-t border-graphite-900/8 pt-3 ' : '') + 'flex gap-2">'
            + col(T.hour, hours, sel.h, 'data-dp-hour') + '<div class="self-center pt-5 font-semibold text-steel">:</div>' + col(T.minute, mins, sel.i, 'data-dp-min') + '</div>';
    }

    /* ---------------------------------------------------------- actions */

    function setPart(part, n) {
        var kind = cur.kind, p = parse(kind, VALUE.get.call(cur.input)), now = new Date();
        if (kind === 'datetime-local' && p.y === null) { p.y = now.getFullYear(); p.m = now.getMonth(); p.d = now.getDate(); }
        if (p.h === null) { p.h = part === 'h' ? n : 9; p.i = 0; }
        p[part] = n;
        var t = pad(p.h) + ':' + pad(p.i);
        commit(kind === 'time' ? t : ymd(p.y, p.m, p.d) + 'T' + t);
    }

    function onPanelClick(e) {
        e.stopPropagation();                        // the redraw below detaches e.target: the outside-click check must not see it
        var t = e.target.closest('button');
        if (!t || t.disabled || !cur) return;
        var kind = cur.kind, input = cur.input, p = parse(kind, VALUE.get.call(input)), keep;
        if (t.hasAttribute('data-dp-nav')) {
            var n = +t.getAttribute('data-dp-nav');
            if (cur.view === 'days') { cur.m += n; if (cur.m < 0) { cur.m = 11; cur.y--; } if (cur.m > 11) { cur.m = 0; cur.y++; } }
            else cur.y += cur.view === 'months' ? n : 12 * n;
            redraw('[data-dp-nav="' + n + '"]');
        } else if (t.hasAttribute('data-dp-view')) {
            cur.view = cur.view === 'days' ? 'months' : 'years';
            redraw();
        } else if (t.hasAttribute('data-dp-year')) {
            cur.y = +t.getAttribute('data-dp-year'); cur.view = 'months'; redraw();
        } else if (t.hasAttribute('data-dp-month')) {
            cur.m = +t.getAttribute('data-dp-month');
            if (kind === 'month') { commit(ymd(cur.y, cur.m, 1).slice(0, 7)); close(true); return; }
            cur.view = 'days'; redraw();
        } else if (t.hasAttribute('data-dp-day')) {
            var iso = t.getAttribute('data-dp-day');
            if (kind === 'date') { commit(iso); close(true); return; }
            commit(iso + 'T' + (p.h !== null ? pad(p.h) + ':' + pad(p.i) : '09:00'));
            keep = parse(kind, iso); cur.y = keep.y; cur.m = keep.m; redraw('[data-dp-day="' + iso + '"]');
        } else if (t.hasAttribute('data-dp-hour')) { setPart('h', +t.getAttribute('data-dp-hour')); redraw('[data-dp-hour="' + t.getAttribute('data-dp-hour') + '"]'); }
        else if (t.hasAttribute('data-dp-min')) { setPart('i', +t.getAttribute('data-dp-min')); redraw('[data-dp-min="' + t.getAttribute('data-dp-min') + '"]'); }
        else if (t.hasAttribute('data-dp-today')) {
            var d = new Date(), today = ymd(d.getFullYear(), d.getMonth(), d.getDate()), hm = pad(d.getHours()) + ':' + pad(d.getMinutes());
            commit(kind === 'date' ? today : kind === 'month' ? today.slice(0, 7) : kind === 'time' ? hm : today + 'T' + hm);
            close(true);
        } else if (t.hasAttribute('data-dp-clear')) { commit(''); close(true); }
        else if (t.hasAttribute('data-dp-done')) { close(true); }
    }

    /** Redraw, keeping the column scroll and putting focus back on the same control. */
    function redraw(focusSel) {
        var scroll = Array.prototype.map.call(panel.querySelectorAll('[data-dp-col]'), function (c) { return c.scrollTop; });
        draw();
        Array.prototype.forEach.call(panel.querySelectorAll('[data-dp-col]'), function (c, i) { c.scrollTop = scroll[i] || 0; });
        place();
        var f = (focusSel && panel.querySelector(focusSel)) || panel.querySelector('[data-dp-focus]') || panel.querySelector('[data-dp-view]') || panel.querySelector('button');
        if (f) f.focus({ preventScroll: true });
    }

    function onPanelKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
        if (e.key === 'Tab') {                                                            // keep focus inside while open
            var all = Array.prototype.filter.call(panel.querySelectorAll('button'), function (b) { return !b.disabled && b.tabIndex !== -1; });
            if (!all.length) return;
            if (e.shiftKey && document.activeElement === all[0]) { e.preventDefault(); all[all.length - 1].focus(); }
            else if (!e.shiftKey && document.activeElement === all[all.length - 1]) { e.preventDefault(); all[0].focus(); }
            return;
        }
        var t = document.activeElement, iso = t && t.getAttribute('data-dp-day');
        var move = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
        if (iso && (move || e.key === 'PageUp' || e.key === 'PageDown')) {
            e.preventDefault();
            var p = parse('date', iso), d = move ? new Date(p.y, p.m, p.d + move) : new Date(p.y, p.m + (e.key === 'PageUp' ? -1 : 1), p.d);
            var to = ymd(d.getFullYear(), d.getMonth(), d.getDate());
            if (d.getMonth() !== cur.m || d.getFullYear() !== cur.y) { cur.y = d.getFullYear(); cur.m = d.getMonth(); draw(); place(); }
            var cell = panel.querySelector('[data-dp-day="' + to + '"]');
            if (cell) { panel.querySelectorAll('[data-dp-day]').forEach(function (c) { c.tabIndex = -1; }); cell.tabIndex = 0; cell.focus(); }
        }
    }

    /* ----------------------------------------------------------- wiring */

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-dp-btn]');
        if (b && b._input) { openFor(b._input); return; }
        var l = e.target.closest('label[for]'), c = l && document.getElementById(l.htmlFor);
        if (c && c._dp) { e.preventDefault(); openFor(c); return; }
        if (cur && panel && !panel.contains(e.target)) close();
    });
    document.addEventListener('keydown', function (e) {
        var b = e.target.closest && e.target.closest('[data-dp-btn]');
        if (b && b._input && e.key === 'ArrowDown') { e.preventDefault(); openFor(b._input); }
    });
    window.addEventListener('resize', function () { close(); });
    // Follow the field while the page scrolls (phones scroll on focus); close once it has left the screen.
    document.addEventListener('scroll', function (e) {
        if (!cur || panel.contains(e.target)) return;
        var r = cur.input._dp.btn.getBoundingClientRect();
        if (r.bottom < 0 || r.top > window.innerHeight) close(); else place();
    }, true);

    // The native "required" check does not see hidden inputs, so do it here (before any script submits the form).
    document.addEventListener('submit', function (e) {
        var missing = Array.prototype.filter.call(e.target.querySelectorAll('input[data-dp][required]'), function (i) {
            return i._dp && !i.disabled && !VALUE.get.call(i) && i._dp.btn.offsetParent !== null;
        });
        if (!missing.length) return;
        e.preventDefault(); e.stopImmediatePropagation();
        missing.forEach(function (i) { i._dp.btn.setAttribute('aria-invalid', 'true'); i._dp.btn.classList.add('!ring-2', '!ring-signal-600'); i._dp.btn.title = T.required; });
        missing[0]._dp.btn.scrollIntoView({ block: 'center' });
        openFor(missing[0]);
    }, true);

    function start() {
        scan(document.body);
        new MutationObserver(function (list) {
            list.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (!panel || !panel.contains(n)) scan(n); }); });
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
