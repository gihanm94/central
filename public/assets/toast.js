/* Toasts (bottom right). window.toast(message, 'success' | 'error' | 'info'). Server flash messages arrive as <div data-toast data-type="…">; a toast can also
   be kept across a reload: toast.later(message, type). */
(function () {
    'use strict';
    var KEY = 'acme_toast', box;
    var ICON = {
        success: '<svg viewBox="0 0 24 24" class="size-5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.7 2.7L16 9.8"/></svg>',
        error: '<svg viewBox="0 0 24 24" class="size-5 shrink-0 text-signal-600" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.2v.1"/></svg>',
        info: '<svg viewBox="0 0 24 24" class="size-5 shrink-0 text-sky-600" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.8v.1"/></svg>'
    };
    function root() {
        if (box) return box;
        box = document.createElement('div');
        box.setAttribute('aria-live', 'polite');
        box.className = 'pointer-events-none fixed bottom-4 right-4 z-[200] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2';
        document.body.appendChild(box);
        return box;
    }
    function toast(msg, type) {
        type = ICON[type] ? type : 'info';
        var t = document.createElement('div');
        t.setAttribute('role', type === 'error' ? 'alert' : 'status');
        t.className = 'pointer-events-auto flex items-start gap-3 rounded-lg bg-white px-4 py-3 text-sm text-graphite-900 shadow-xl ring-1 ring-graphite-900/10';
        t.style.cssText = 'opacity:0;transform:translateY(8px);transition:opacity .2s,transform .2s';
        var span = document.createElement('span'); span.className = 'min-w-0 flex-1 break-words'; span.textContent = msg;
        var x = document.createElement('button'); x.type = 'button'; x.className = 'text-graphite-400 hover:text-graphite-900'; x.setAttribute('aria-label', 'Close'); x.innerHTML = '&times;';
        t.insertAdjacentHTML('afterbegin', ICON[type]); t.appendChild(span); t.appendChild(x);
        root().appendChild(t);
        requestAnimationFrame(function () { t.style.opacity = 1; t.style.transform = 'none'; });
        var gone = function () { t.style.opacity = 0; t.style.transform = 'translateY(8px)'; setTimeout(function () { t.remove(); }, 220); };
        x.addEventListener('click', gone);
        setTimeout(gone, type === 'error' ? 9000 : 4500);
    }
    toast.later = function (msg, type) { try { sessionStorage.setItem(KEY, JSON.stringify({ m: msg, t: type })); } catch (e) { /* private mode */ } };
    window.toast = toast;
    function boot() {
        document.querySelectorAll('[data-toast]').forEach(function (n) { toast(n.textContent.trim(), n.dataset.type); n.remove(); });
        try { var p = sessionStorage.getItem(KEY); if (p) { sessionStorage.removeItem(KEY); p = JSON.parse(p); toast(p.m, p.t); } } catch (e) { /* ignore */ }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
