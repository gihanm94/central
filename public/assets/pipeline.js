/*
 | Pipeline board: drag and drop between stages (only your own cards), "Move to" menu as a no-drag alternative,
 | more cards load as a column is scrolled, search as you type (3+ letters).
 */
(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var board = $('[data-board]'); if (!board) return;
    function csrf() { var m = $('meta[name="csrf-token"]'); return m ? m.content : ''; }
    var needsReason = (board.dataset.needsReason || '').split(',');
    var dragging = null, toastTimer;

    function toast(msg, bad) {
        var t = $('#kb-toast'); t.textContent = msg; t.style.background = bad ? 'var(--color-signal-700)' : ''; t.style.opacity = 1;
        clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.style.opacity = 0; }, 3200);
    }
    function params(extra) {
        var p = new URLSearchParams(location.search); Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); }); return p;
    }
    function col(stage) { return $('.kb-col[data-stage="' + stage + '"]', board); }
    function tidy(c) {
        var list = $('[data-list]', c), has = $$('.kb-card', list).length > 0;
        $('[data-empty]', list).hidden = has;
    }
    function applyStats(stats) {
        Object.keys(stats).forEach(function (code) {
            var c = col(code); if (!c) return;
            $('[data-count]', c).textContent = stats[code].n;
            var cur = $('[data-sum]', c).textContent.split(' ')[0];
            $('[data-sum]', c).textContent = cur + ' ' + compact(stats[code].v);
        });
    }
    function compact(n) { var a = Math.abs(n); var u = [[1e9, 'B'], [1e6, 'M'], [1e3, 'K']]; for (var i = 0; i < u.length; i++) if (a >= u[i][0]) return (n / u[i][0]).toFixed(1).replace(/\.0$/, '') + u[i][1]; return Math.round(n).toLocaleString(); }

    /* ----------------------------------------------------------- moving */

    function doMove(card, stage, note) {
        var from = card.closest('.kb-col'), id = card.dataset.id;
        var body = Object.assign({}, Object.fromEntries(params().entries()), { id: id, stage: stage, note: note || '' });
        return fetch(board.dataset.moveUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body) })
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message || 'Could not move it.'); return d; }); })
            .then(function (d) {
                var to = col(stage), list = $('[data-list]', to), tmp = document.createElement('div');
                tmp.innerHTML = d.html; var fresh = tmp.firstElementChild;
                card.remove();
                if (fresh) list.insertBefore(fresh, list.firstChild);
                applyStats(d.stats); tidy(from); tidy(to); toast(d.message);
            })
            .catch(function (err) { toast(err.message, true); });
    }

    var dlg = $('#move-dialog'), reason = $('[data-move-reason]', dlg), go = $('[data-move-go]', dlg), pending = null;
    function requestMove(card, stage) {
        if (card.dataset.stage === stage) return;
        if (needsReason.indexOf(stage) > -1) {
            pending = { card: card, stage: stage };
            var name = $('.kb-col[data-stage="' + stage + '"] h2 span:last-child', board).textContent;
            $('[data-move-title]', dlg).textContent = name; reason.value = ''; go.disabled = true; dlg.showModal(); reason.focus();
        } else doMove(card, stage, '');
    }
    reason.addEventListener('input', function () { go.disabled = reason.value.trim() === ''; });
    $('[data-move-cancel]', dlg).addEventListener('click', function () { pending = null; dlg.close(); });
    $('[data-move-form]', dlg).addEventListener('submit', function (e) { e.preventDefault(); if (!pending || !reason.value.trim()) return; var p = pending; pending = null; dlg.close(); doMove(p.card, p.stage, reason.value.trim()); });

    // menu fallback (touch screens, keyboard)
    document.addEventListener('click', function (e) {   // the menu opens from <body>, so listen on the document
        var b = e.target.closest('[data-move-to]'); if (!b) return;
        var card = board.querySelector('.kb-card[data-id="' + b.closest('[data-menu-panel]').id.replace('cm-', '') + '"]');
        document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        if (card) requestMove(card, b.dataset.moveTo);
    });

    // drag and drop
    board.addEventListener('dragstart', function (e) {
        var card = e.target.closest && e.target.closest('.kb-card[draggable="true"]'); if (!card) return;
        dragging = card; card.classList.add('opacity-50'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', card.dataset.id);
    });
    board.addEventListener('dragend', function () { if (dragging) dragging.classList.remove('opacity-50'); dragging = null; $$('.kb-col', board).forEach(function (c) { c.style.outline = ''; }); });
    board.addEventListener('dragover', function (e) {
        var c = e.target.closest('.kb-col'); if (!c || !dragging) return;
        e.preventDefault(); e.dataTransfer.dropEffect = c.dataset.stage === dragging.dataset.stage ? 'none' : 'move';
        $$('.kb-col', board).forEach(function (x) { x.style.outline = x === c && c.dataset.stage !== dragging.dataset.stage ? '2px dashed ' + c.dataset.color : ''; });
        // scroll the board sideways near its edges
        var sc = $('[data-board-scroll]', board), r = sc.getBoundingClientRect();
        if (e.clientX > r.right - 60) sc.scrollLeft += 14; else if (e.clientX < r.left + 60) sc.scrollLeft -= 14;
    });
    board.addEventListener('drop', function (e) {
        var c = e.target.closest('.kb-col'); if (!c || !dragging) return;
        e.preventDefault(); c.style.outline = ''; var card = dragging; dragging = null; card.classList.remove('opacity-50');
        requestMove(card, c.dataset.stage);
    });

    /* ---------------------------------------------------- endless scroll */

    function more(c) {
        var list = $('[data-list]', c);
        if (list.dataset.more !== '1' || list.dataset.busy === '1') return;
        list.dataset.busy = '1'; var ld = $('[data-loading]', list); ld.hidden = false;
        var page = parseInt(list.dataset.page, 10) + 1;
        fetch(board.dataset.cardsUrl + '?' + params({ stage: c.dataset.stage, page: page }).toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var tmp = document.createElement('div'); tmp.innerHTML = d.html;
                while (tmp.firstElementChild) list.insertBefore(tmp.firstElementChild, ld);
                list.dataset.page = page; list.dataset.more = d.more ? '1' : '0';
            })
            .finally(function () { list.dataset.busy = '0'; ld.hidden = true; });
    }
    board.addEventListener('scroll', function (e) {
        var list = e.target; if (!list.matches || !list.matches('[data-list]')) return;
        if (list.scrollTop + list.clientHeight >= list.scrollHeight - 80) more(list.closest('.kb-col'));
    }, true);
    // a short column that does not fill its box still needs its next page
    $$('.kb-col', board).forEach(function (c) { var l = $('[data-list]', c); if (l.dataset.more === '1' && l.scrollHeight <= l.clientHeight + 4) more(c); });

    /* ------------------------------------------------------------ search */

    var input = $('[data-board-search]'), timer, last = input ? input.value.trim() : '';
    if (input) input.addEventListener('input', function () {
        var v = input.value.trim(); clearTimeout(timer);
        if (v === last || (v.length > 0 && v.length < 3)) return;
        timer = setTimeout(function () {
            last = v; var p = new URLSearchParams(location.search); v ? p.set('q', v) : p.delete('q');
            var url = location.pathname + (p.toString() ? '?' + p.toString() : '');
            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html'), fresh = $('[data-board]', doc);
                if (fresh) { board.innerHTML = fresh.innerHTML; history.replaceState(null, '', url); $$('.kb-col', board).forEach(function (c) { var l = $('[data-list]', c); if (l.dataset.more === '1' && l.scrollHeight <= l.clientHeight + 4) more(c); }); }
            });
        }, 350);
    });
})();
