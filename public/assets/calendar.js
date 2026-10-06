/* Calendar: month / week / agenda over /calendar/events. Clicking an empty day opens the new-activity side sheet, clicking a
   CRM event opens its edit sheet (saving reloads the page, the view is kept), Google events show a small popover. */
(function () {
    'use strict';
    var root = document.getElementById('cal'); if (!root) return;
    var C = JSON.parse(root.dataset.config), T = C.i18n, pop = document.getElementById('cal-pop');
    var KEY = 'cal-state', st = { view: 'month', at: new Date() };
    try { var s = JSON.parse(localStorage.getItem(KEY) || 'null'); if (s) { st.view = s.view || 'month'; st.at = s.at ? new Date(s.at) : st.at; } } catch (e) {}
    var cache = {}, TONE = { CALL: 'bg-sky-100 text-sky-800', MEETING: 'bg-violet-100 text-violet-800', EMAIL: 'bg-graphite-100 text-graphite-700', TASK: 'bg-amber-100 text-amber-800', GOOGLE: 'bg-emerald-100 text-emerald-800' };
    function p2(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()); }
    function addDays(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
    function mondayOf(d) { var x = new Date(d.getFullYear(), d.getMonth(), d.getDate()); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function hm(iso) { return iso.length > 10 ? iso.substr(11, 5) : ''; }
    function save() { try { localStorage.setItem(KEY, JSON.stringify({ view: st.view, at: st.at.toISOString() })); } catch (e) {} }

    function range() {
        var a = st.at, f, t;
        if (st.view === 'month') { f = mondayOf(new Date(a.getFullYear(), a.getMonth(), 1)); t = addDays(f, 42); }
        else if (st.view === 'week') { f = mondayOf(a); t = addDays(f, 7); }
        else { f = new Date(a.getFullYear(), a.getMonth(), a.getDate()); t = addDays(f, 30); }
        return { f: f, t: t };
    }
    function load(cb) {
        var r = range(), k = ymd(r.f) + '|' + ymd(r.t);
        if (cache[k]) return cb(cache[k], r);
        root.style.opacity = .5;
        fetch(C.events + '?from=' + ymd(r.f) + '&to=' + ymd(r.t) + (C.mine ? '&mine=1' : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (x) { return x.json(); }).then(function (d) { cache[k] = d.events || []; root.style.opacity = 1; cb(cache[k], r, d.warning); })
            .catch(function () { root.style.opacity = 1; root.innerHTML = '<p class="p-6 text-sm text-signal-700">Network error.</p>'; });
    }
    function chip(e) {
        var t = e.all_day ? '' : hm(e.start) + ' ';
        return '<button type="button" data-ev="' + esc(e.id) + '" class="mb-0.5 block w-full truncate rounded px-1.5 py-0.5 text-left text-xs ' + (TONE[e.type] || TONE.TASK) + (e.status === 'DONE' ? ' line-through opacity-70' : '') + '">' + esc(t + e.title) + '</button>';
    }
    function byDay(evs) {
        var m = {}; evs.forEach(function (e) {
            var s = new Date(e.start.substr(0, 10) + 'T00:00:00'), en = e.end ? new Date(e.end.substr(0, 10) + 'T00:00:00') : s;
            if (e.all_day && e.end) en = addDays(en, -1);                     // Google all-day end is exclusive
            for (var d = s, n = 0; d <= en && n < 31; d = addDays(d, 1), n++) (m[ymd(d)] = m[ymd(d)] || []).push(e);
        }); return m;
    }
    function render(evs, r, warn) {
        var days = byDay(evs), today = ymd(new Date()), h = '', title;
        var mn = st.at.toLocaleString(document.documentElement.lang || undefined, { month: 'long', year: 'numeric' });
        if (st.view === 'agenda') {
            title = ymd(r.f) + ' → ' + ymd(addDays(r.t, -1)); var any = false;
            Object.keys(days).sort().forEach(function (k) {
                if (k < ymd(r.f) || k >= ymd(r.t)) return; any = true;
                h += '<div class="border-b border-graphite-900/6 px-4 py-3"><div class="mb-1 text-sm font-semibold ' + (k === today ? 'text-signal-700' : '') + '">' + new Date(k + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }) + '</div>' +
                    days[k].map(function (e) { return '<div class="flex gap-3 py-0.5 text-sm"><span class="w-20 shrink-0 text-steel">' + esc(e.all_day ? T.allday : hm(e.start) + '–' + hm(e.end)) + '</span><div class="min-w-0 flex-1">' + chip(Object.assign({}, e, { start: e.start.substr(0, 10), all_day: true })) + '</div></div>'; }).join('') + '</div>';
            });
            if (!any) h = '<p class="px-5 py-10 text-center text-sm text-steel">' + T.nothing + '</p>';
        } else {
            var n = st.view === 'month' ? 42 : 7;
            title = st.view === 'month' ? mn : ymd(r.f) + ' → ' + ymd(addDays(r.t, -1));
            h = '<div class="grid grid-cols-7 border-b border-graphite-900/8 bg-mist/60 text-center text-xs font-medium text-steel">' + C.days.map(function (d) { return '<div class="py-2">' + esc(d) + '</div>'; }).join('') + '</div><div class="grid grid-cols-7">';
            for (var i = 0; i < n; i++) {
                var d = addDays(r.f, i), k = ymd(d), list = days[k] || [], other = st.view === 'month' && d.getMonth() !== st.at.getMonth(), max = st.view === 'month' ? 3 : 50;
                h += '<div data-day="' + k + '" style="min-height:' + (st.view === 'month' ? '6.5' : '22') + 'rem" class="cursor-pointer border-b border-r border-graphite-900/6 p-1.5 ' + (other ? 'bg-mist/40 text-steel' : '') + '">' +
                    '<div class="mb-1 text-right text-xs ' + (k === today ? '"><span class="rounded-full bg-signal-600 px-1.5 py-0.5 font-semibold text-white">' + d.getDate() + '</span>' : '">' + d.getDate()) + '</div>' +
                    list.slice(0, max).map(chip).join('') + (list.length > max ? '<div class="text-xs text-steel">+' + (list.length - max) + '</div>' : '') + '</div>';
            }
            h += '</div>';
        }
        document.getElementById('cal-title').textContent = title;
        if (warn) h = '<p class="bg-amber-50 px-4 py-2 text-xs text-amber-800">' + esc(warn) + '</p>' + h;
        root.innerHTML = h;
        Array.prototype.forEach.call(document.querySelectorAll('[data-cal-view]'), function (b) {
            var on = b.dataset.calView === st.view; b.className = 'rounded-md px-3 py-1.5 text-sm ' + (on ? 'bg-signal-600 font-medium text-white' : 'text-steel hover:bg-mist');
        });
        root._evs = evs;
    }
    function go() { save(); pop.classList.add('hidden'); load(render); }

    function showPop(ev, el) {
        var h = '<div class="flex items-start justify-between gap-2"><b class="min-w-0 break-words">' + esc(ev.title) + '</b><button type="button" data-pop-x class="text-steel">&times;</button></div>' +
            '<p class="mt-1 text-steel">' + esc(ev.all_day ? T.allday : ev.start.replace('T', ' ').substr(0, 16) + ' – ' + hm(ev.end)) + '</p>' + (ev.location ? '<p class="mt-1">' + esc(ev.location) + '</p>' : '') +
            '<p class="mt-1 text-xs text-steel">' + esc(T.google) + '</p>' + (ev.link ? '<a class="btn-secondary mt-3 !h-8" target="_blank" rel="noopener noreferrer" href="' + esc(ev.link) + '">' + esc(T.open) + '</a>' : '');
        pop.innerHTML = h; pop.classList.remove('hidden');
        var b = el.getBoundingClientRect(); pop.style.top = Math.min(window.innerHeight - pop.offsetHeight - 8, Math.max(8, b.bottom + 4)) + 'px'; pop.style.left = Math.min(window.innerWidth - 296, Math.max(8, b.left)) + 'px';
    }
    root.addEventListener('click', function (e) {
        var ev = e.target.closest('[data-ev]');
        if (ev) {
            e.stopPropagation();
            var o = (root._evs || []).filter(function (x) { return x.id === ev.dataset.ev; })[0]; if (!o) return;
            if (o.source === 'crm') window.AcmeUI.sheet(o.url, T.activity, ev); else showPop(o, ev);
            return;
        }
        var day = e.target.closest('[data-day]');
        if (day && C.canCreate) window.AcmeUI.sheet(C.create + '?start=' + day.dataset.day + 'T09:00', T.new, day);
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('#cal-pop') && !e.target.closest('[data-ev]')) pop.classList.add('hidden'); if (e.target.closest('[data-pop-x]')) pop.classList.add('hidden'); });
    document.getElementById('cal-bar').addEventListener('click', function (e) {
        var b = e.target.closest('[data-cal],[data-cal-view]'); if (!b) return;
        if (b.dataset.calView) { st.view = b.dataset.calView; return go(); }
        var dir = b.dataset.cal;
        if (dir === 'today') st.at = new Date();
        else { var n = dir === 'next' ? 1 : -1; if (st.view === 'month') st.at = new Date(st.at.getFullYear(), st.at.getMonth() + n, 1); else st.at = addDays(st.at, n * (st.view === 'week' ? 7 : 30)); }
        go();
    });
    go();
})();
