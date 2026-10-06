/* Live viewer for the accounting log. AcctLog.stream(box, {url, day, run, level, ch, q, every, onEntries}) → {stop, set(filters), reset} */
(function () {
    var TONE = { debug: 'text-graphite-400', info: 'text-sky-300', warn: 'text-amber-300', error: 'text-red-400' };
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function line(e) {
        var c = e.ctx ? '<details class="ml-[7.5rem] text-graphite-300"><summary class="cursor-pointer select-none text-xs text-graphite-400">details</summary><pre class="whitespace-pre-wrap break-all text-xs">' + esc(JSON.stringify(e.ctx, null, 1)) + '</pre></details>' : '';
        return '<div class="log-line py-0.5" data-level="' + esc(e.level) + '"><span class="text-graphite-500">' + esc((e.t || '').slice(11)) + '</span> '
            + '<span class="inline-block w-12 font-semibold uppercase ' + (TONE[e.level] || '') + '">' + esc(e.level) + '</span> '
            + '<span class="inline-block w-12 text-graphite-400">' + esc(e.ch || '') + '</span> '
            + '<span class="' + (e.level === 'error' ? 'text-red-300' : e.level === 'warn' ? 'text-amber-200' : 'text-white') + '">' + esc(e.msg || '') + '</span>' + c + '</div>';
    }
    function stream(box, o) {
        var offset = -1, timer = null, stopped = false, paused = false, f = Object.assign({ day: '', run: '', level: 'debug', ch: '', q: '' }, o), busy = false, empty = box.dataset.empty || '';
        function tick() {
            if (stopped || busy) return;
            busy = true;
            var p = new URLSearchParams({ day: f.day, after: offset, level: f.level, ch: f.ch, q: f.q, run: f.run, tail: o.tail || 300 });
            fetch(o.url + '?' + p, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                .then(function (d) {
                    busy = false;
                    if (d.size < offset) { offset = -1; box.innerHTML = ''; }       // the file was cleared
                    else if (d.entries.length) {
                        if (offset < 0) box.innerHTML = '';
                        var stick = box.scrollTop + box.clientHeight >= box.scrollHeight - 40;
                        box.insertAdjacentHTML('beforeend', d.entries.map(line).join(''));
                        while (box.childElementCount > 2000) box.removeChild(box.firstChild);
                        if (stick && !paused) box.scrollTop = box.scrollHeight;
                        if (o.onEntries) o.onEntries(d.entries);
                    } else if (offset < 0 && !box.childElementCount) box.innerHTML = '<div class="text-graphite-500">' + esc(empty) + '</div>';
                    offset = d.offset;
                    if (o.onTick) o.onTick(d);
                    timer = setTimeout(tick, o.every || 1500);
                })
                .catch(function () { busy = false; timer = setTimeout(tick, 4000); });
        }
        tick();
        return {
            stop: function () { stopped = true; clearTimeout(timer); },
            pause: function (v) { paused = v; },
            set: function (n) { Object.assign(f, n); offset = -1; box.innerHTML = ''; clearTimeout(timer); busy = false; tick(); },
            now: function () { clearTimeout(timer); busy = false; tick(); }
        };
    }
    window.AcctLog = { stream: stream, line: line };
})();
