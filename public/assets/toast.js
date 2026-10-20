/* Toasts, Sonner style: top right, just under the header, newest on top.
   window.toast(message, 'success' | 'error' | 'warning' | 'info' | 'loading', { description, duration })
   toast.success / .error / .warning / .info / .loading(message, opts) — each returns { update(message, type, opts), dismiss() }.
   A message with a line break shows its first line as the title and the rest as the description.
   Server flash messages arrive as <div data-toast data-type="…">; a toast can be kept across a reload: toast.later(message, type).
   Export / download links: a "Preparing…" toast that turns into "Download started" once the file arrives (see public/index.php, _dl). */
(function () {
    'use strict';
    var KEY = 'acme_toast', MAX = 4, box;
    var TONE = {
        success: { ring: 'ring-emerald-600/20', bar: 'bg-emerald-500', icon: 'bg-emerald-50 text-emerald-600', ms: 4500,
            svg: '<path d="m8 12.5 2.7 2.7L16 9.8"/>' },
        error:   { ring: 'ring-signal-600/25', bar: 'bg-signal-600', icon: 'bg-signal-50 text-signal-600', ms: 9000,
            svg: '<path d="M12 7.5v5.5M12 16.3v.1"/>' },
        warning: { ring: 'ring-amber-500/30', bar: 'bg-amber-500', icon: 'bg-amber-50 text-amber-600', ms: 7000,
            svg: '<path d="M12 8v5M12 16.3v.1"/>' },
        info:    { ring: 'ring-sky-600/20', bar: 'bg-sky-500', icon: 'bg-sky-50 text-sky-600', ms: 4500,
            svg: '<path d="M12 11v5M12 7.8v.1"/>' },
        loading: { ring: 'ring-graphite-900/10', bar: 'bg-graphite-400', icon: 'bg-graphite-900/6 text-graphite-700', ms: 0,
            svg: '<path d="M12 3a9 9 0 1 0 9 9"/>' }
    };
    function root() {
        if (box && document.body.contains(box)) return box;
        box = document.createElement('section');
        box.setAttribute('aria-label', 'Notifications');
        box.setAttribute('aria-live', 'polite');
        box.className = 'pointer-events-none fixed right-3 top-[4.25rem] z-[200] flex w-[min(24rem,calc(100vw-1.5rem))] flex-col gap-2 sm:right-5 sm:top-[4.75rem]';
        document.body.appendChild(box);
        return box;
    }
    function icon(type) {
        var circle = type === 'loading' ? '' : '<circle cx="12" cy="12" r="9"/>';
        return '<span class="mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-full ' + TONE[type].icon + '">'
            + '<svg viewBox="0 0 24 24" class="size-[18px]' + (type === 'loading' ? ' animate-spin' : '') + '" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + circle + TONE[type].svg + '</svg></span>';
    }
    function split(msg, opts) {
        msg = String(msg == null ? '' : msg).trim();
        var desc = opts && opts.description, i = msg.indexOf('\n');
        if (!desc && i > 0) { desc = msg.slice(i + 1).trim(); msg = msg.slice(0, i).trim(); }
        return [msg, desc || ''];
    }

    function toast(msg, type, opts) {
        type = TONE[type] ? type : (type === 'warn' ? 'warning' : 'info');
        opts = opts || {};
        var t = document.createElement('div'), timer = null, left = 0, started = 0, closed = false;
        t.className = 'pointer-events-auto relative overflow-hidden rounded-xl bg-white text-sm text-graphite-900 shadow-lg shadow-graphite-900/10 ring-1';
        t.style.cssText = 'opacity:0;transform:translateY(-10px) scale(.98);transition:opacity .22s ease,transform .22s ease,margin .22s ease';
        t.innerHTML = '<div class="flex items-start gap-3 py-3 pl-3.5 pr-9" data-body></div>'
            + '<button type="button" class="absolute right-2 top-2 inline-flex size-6 items-center justify-center rounded-md text-graphite-400 hover:bg-graphite-900/6 hover:text-graphite-900" aria-label="Close">'
            + '<svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>'
            + '<span class="absolute bottom-0 left-0 h-0.5 w-full origin-left" data-bar></span>';
        var body = t.querySelector('[data-body]'), bar = t.querySelector('[data-bar]');

        function paint(m, ty, o) {
            type = TONE[ty] ? ty : type;
            var p = split(m, o);
            t.setAttribute('role', type === 'error' || type === 'warning' ? 'alert' : 'status');
            t.className = t.className.replace(/\bring-\S+\/\d+\b/g, '').trim() + ' ' + TONE[type].ring;
            body.innerHTML = icon(type) + '<div class="min-w-0 flex-1 pt-1"><p class="break-words font-medium leading-snug" data-title></p>'
                + (p[1] ? '<p class="mt-0.5 whitespace-pre-line break-words text-[13px] leading-snug text-steel" data-desc></p>' : '') + '</div>';
            body.querySelector('[data-title]').textContent = p[0];
            if (p[1]) body.querySelector('[data-desc]').textContent = p[1];
            bar.className = 'absolute bottom-0 left-0 h-0.5 w-full origin-left opacity-60 ' + TONE[type].bar;
            schedule(o && o.duration != null ? o.duration : TONE[type].ms);
        }
        function schedule(ms) {
            clearTimeout(timer); left = ms;
            bar.style.transition = 'none'; bar.style.transform = 'scaleX(1)';
            if (!ms) { bar.style.display = 'none'; return; }
            bar.style.display = '';
            run();
        }
        function run() {
            if (!left || closed) return;
            started = Date.now();
            timer = setTimeout(dismiss, left);
            void bar.offsetWidth;                        // start the bar from where it is now
            bar.style.transition = 'transform ' + left + 'ms linear'; bar.style.transform = 'scaleX(0)';
        }
        function pause() {
            if (!left || !timer) return;
            clearTimeout(timer); timer = null;
            left = Math.max(400, left - (Date.now() - started));
            var w = bar.getBoundingClientRect().width / (t.getBoundingClientRect().width || 1);
            bar.style.transition = 'none'; bar.style.transform = 'scaleX(' + w + ')';
        }
        function dismiss() {
            if (closed) return; closed = true; clearTimeout(timer);
            t.style.opacity = 0; t.style.transform = 'translateX(16px)';
            t.style.marginBottom = -(t.offsetHeight + 8) + 'px';
            setTimeout(function () { t.remove(); }, 240);
        }
        t.addEventListener('mouseenter', pause);
        t.addEventListener('mouseleave', function () { if (!closed && left && !timer) run(); });
        t.querySelector('button').addEventListener('click', dismiss);

        var b = root();
        b.insertBefore(t, b.firstChild);
        var extra = Array.prototype.slice.call(b.children, MAX);
        extra.forEach(function (n) { n.remove(); });
        paint(msg, type, opts);
        requestAnimationFrame(function () { t.style.opacity = 1; t.style.transform = 'none'; });

        return { update: function (m, ty, o) { if (!closed) paint(m, ty, o); }, dismiss: dismiss, el: t };
    }
    ['success', 'error', 'warning', 'info', 'loading'].forEach(function (k) { toast[k] = function (m, o) { return toast(m, k, o); }; });
    toast.later = function (msg, type) { try { sessionStorage.setItem(KEY, JSON.stringify({ m: msg, t: type })); } catch (e) { /* private mode */ } };
    window.toast = toast;

    /* Export / download / template links: say it is being prepared, then that it started (the server sets cookie dl_<token>). */
    var L = document.documentElement.lang === 'th'
        ? { prep: 'กำลังเตรียมไฟล์…', done: 'เริ่มดาวน์โหลดแล้ว', slow: 'ไฟล์ยังไม่มา', slowDesc: 'ถ้าดาวน์โหลดไม่เริ่ม ลองอีกครั้ง' }
        : { prep: 'Preparing the file…', done: 'Download started', slow: 'The file has not arrived yet', slowDesc: 'If the download does not start, try again.' };
    function isDownload(a) {
        if (a.hasAttribute('data-no-download-toast') || a.target === '_blank') return false;
        if (a.hasAttribute('download') || a.hasAttribute('data-download')) return true;
        try { var u = new URL(a.href, location.href); } catch (e) { return false; }
        return u.origin === location.origin && /\/(export|download|template)$/.test(u.pathname);
    }
    function cookie(name) { var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : null; }
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || !isDownload(a)) return;
        var tok = Math.random().toString(36).slice(2, 12), u = new URL(a.href, location.href);
        u.searchParams.set('_dl', tok);
        var name = (a.getAttribute('title') || a.textContent || '').trim().replace(/\s+/g, ' ');
        var h = toast.loading(L.prep + (name && name.length < 40 ? '\n' + name : ''));
        e.preventDefault();
        location.href = u.toString();
        var tries = 0, poll = setInterval(function () {
            var v = cookie('dl_' + tok);
            if (v) {
                clearInterval(poll); document.cookie = 'dl_' + tok + '=; Max-Age=0; path=/';
                h.update(L.done + (v !== '1' ? '\n' + v : ''), 'success');
            } else if (++tries > 120) { clearInterval(poll); h.update(L.slow + '\n' + L.slowDesc, 'warning'); }
        }, 500);
    });

    function boot() {
        document.querySelectorAll('[data-toast]').forEach(function (n) { toast(n.textContent, n.dataset.type); n.remove(); });
        try { var p = sessionStorage.getItem(KEY); if (p) { sessionStorage.removeItem(KEY); p = JSON.parse(p); toast(p.m, p.t); } } catch (e) { /* ignore */ }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
