/* ERP connection page: follow the sync while the page is open (status every 3 s) and show the sync log live. */
(function () {
    'use strict';
    var box = document.getElementById('erp-live');
    if (!box) return;
    var L = function (k) { return box.dataset['l' + k.charAt(0).toUpperCase() + k.slice(1)] || ''; };
    var TONE = { ok: 'bg-emerald-50 text-emerald-800', failed: 'bg-signal-50 text-signal-800', running: 'bg-amber-50 text-amber-800', never: 'bg-graphite-900/6 text-graphite-700' };
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function num(n) { return Number(n || 0).toLocaleString(); }
    function $(s, r) { return (r || document).querySelector(s); }

    function stateHtml(st) {
        var h = '<span class="badge ' + (TONE[st.status] || TONE.never) + '">' + esc(st.status) + '</span>';
        if (st.last_run_at) h += ' ' + esc(st.ago) + ' · ' + num(st.saved) + ' ' + esc(L('saved')) + (st.duration_ms ? ' · ' + (Math.round(st.duration_ms / 100) / 10) + 's' : '');
        if (st.error) h += '<span class="block max-w-xs truncate text-signal-700" title="' + esc(st.error) + '">' + esc(st.error) + '</span>';
        return h;
    }
    function runsHtml(runs) {
        return '<ul class="divide-y divide-graphite-900/6 text-sm">' + runs.map(function (r) {
            return '<li class="px-5 py-2.5"><div class="flex items-center justify-between gap-2"><span class="font-medium">' + esc(r.kind) + ' <span class="text-xs font-normal text-steel">· ' + esc(r.source) + '</span></span><span class="badge ' + (TONE[r.status] || TONE.never) + '">' + esc(r.status) + '</span></div>' +
                '<p class="text-xs text-steel">' + esc(r.ago) + ' · <span class="font-medium text-graphite-800">' + num(r.saved) + '</span> ' + esc(L('saved')) + ' / ' + num(r.fetched) + ' ' + esc(L('read')) + (r.message ? ' · ' + esc(String(r.message).slice(0, 80)) : '') + '</p></li>';
        }).join('') + '</ul>';
    }
    function apply(d) {
        var run = d.running > 0;
        $('[data-live-running]', box).textContent = run ? L('syncing') : L('idle');
        var dot = $('[data-live-dot]', box); dot.className = 'size-2 rounded-full ' + (run ? 'animate-pulse bg-amber-500' : 'bg-emerald-500');
        var s = d.scheduler, t = (s.enabled ? L('on') : L('off')), warn = false;
        if (s.ago != null) t += ' · ' + L('looked') + ' ' + (s.ago < 120 ? s.ago + 's' : Math.round(s.ago / 60) + ' min') + ' ' + L('ago') + ' (' + s.by + ')'; else t += ' · ' + L('never');
        if (s.enabled && s.inWindow && (s.ago == null || s.ago > 180)) { t += ' — ' + L('stale'); warn = true; }
        else if (s.enabled && !s.inWindow) t += ' · ' + L('outside');
        var el = $('[data-live-sched]', box); el.textContent = t; el.className = warn ? 'text-signal-700' : 'text-steel';
        d.state.forEach(function (st) { document.querySelectorAll('[data-state="' + st.entity + '"]').forEach(function (n) { n.innerHTML = stateHtml(st); }); });
        var runs = $('[data-runs]'); if (runs && d.runs.length) runs.innerHTML = runsHtml(d.runs);
        var T = d.totals, set = function (k, v, bad) { var n = $('[data-stat="' + k + '"]'); if (n) { n.textContent = v; if (k === 'failed') n.classList.toggle('text-signal-700', bad); } };
        set('today', num(T.today)); set('all', num(T.all_time)); set('failed', num(T.failed_day), +T.failed_day > 0);
        var lo = $('[data-stat="last_ok"]'); if (lo && d.runs.length) { var ok = d.runs.filter(function (r) { return r.status === 'ok'; })[0]; lo.textContent = ok ? ok.ago : '—'; }
        $('[data-live-clock]', box).textContent = new Date().toLocaleTimeString();
    }
    var busy = false;
    function poll() {
        if (busy || document.hidden) return;
        busy = true;
        fetch(box.dataset.url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { busy = false; if (d) apply(d); }).catch(function () { busy = false; });
    }
    poll(); setInterval(poll, 3000);

    var log = document.getElementById('erp-log');
    if (log && window.AcctLog) AcctLog.stream(log, { url: box.dataset.url.replace(/erp\/status$/, 'logs/tail'), ch: 'sync,erp', level: 'info', tail: 120, every: 2000 });
})();
