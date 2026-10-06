/*
 | UI behaviour, no framework, no build step.
 |   data-menu="#id" (+ data-placement)   popover menus (module switcher, language, account)
 |   [data-select]                         Tailwind dropdowns that replace <select>
 |   [data-sidebar-toggle]                 collapse sidebar (desktop) / open drawer (phone)
 |   data-toggle="#id"                     show/hide a panel
 |   data-show-when="field=value"          show a block when a dropdown has that value
 |   data-confirm, data-check-all, data-preview, data-dismiss
 |   passkeys: [data-passkey-login], form[data-passkey-register]
 */
(function () {
    'use strict';

    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var desktop = window.matchMedia('(min-width: 1024px)');
    var openPanel = null, openTrigger = null;

    /* ------------------------------------------------ floating panels */

    function place(panel, trigger, placement) {
        panel.hidden = false;
        var r = trigger.getBoundingClientRect(), vw = window.innerWidth, vh = window.innerHeight;
        panel.style.minWidth = panel.hasAttribute('data-select-panel') ? Math.max(r.width, 176) + 'px' : '';
        var pw = panel.offsetWidth, ph = panel.offsetHeight, top, left;
        if (placement === 'right-start' && r.right + pw + 8 < vw) { left = r.right + 8; top = r.top; }
        else {
            top = r.bottom + 6;
            left = placement === 'bottom-end' ? r.right - pw : r.left;
            if (top + ph > vh - 8 && r.top - ph - 6 > 8) top = r.top - ph - 6;   // flip up
        }
        panel.style.left = Math.max(8, Math.min(left, vw - pw - 8)) + 'px';
        panel.style.top = Math.max(8, Math.min(top, vh - ph - 8)) + 'px';
    }

    function open(panel, trigger, placement) {
        close();
        place(panel, trigger, placement);
        trigger.setAttribute('aria-expanded', 'true');
        openPanel = panel; openTrigger = trigger;
    }

    function close(focusBack) {
        if (!openPanel) return;
        openPanel.hidden = true;
        openTrigger.setAttribute('aria-expanded', 'false');
        if (focusBack) openTrigger.focus();
        openPanel = openTrigger = null;
    }

    window.addEventListener('resize', function () { close(); });
    document.addEventListener('scroll', function (e) { if (openPanel && !openPanel.contains(e.target)) close(); }, true);

    /* ------------------------------------------------------- dropdowns */

    function options(sel) { return $$('[role=option]', sel).filter(function (o) { return !o.hidden; }); }

    function choose(sel, opt) {
        var input = $('[data-select-input]', sel), button = $('[data-select-button]', sel), label = $('[data-select-label]', sel);
        var changed = input.value !== opt.dataset.value;
        input.value = opt.dataset.value;
        var rich = $('[data-rich]', opt);
        if (rich) label.innerHTML = rich.innerHTML; else label.textContent = opt.dataset.label;
        label.classList.toggle('text-graphite-400', opt.dataset.value === '');
        $$('[role=option]', sel).forEach(function (o) { o.setAttribute('aria-selected', o === opt ? 'true' : 'false'); });
        button.className = button.dataset.base + ' ' + (opt.dataset.tone || '') + ' flex w-full items-center justify-between gap-2 text-left disabled:cursor-not-allowed disabled:opacity-70';
        close(true);
        if (changed) {
            input.dispatchEvent(new Event('change', { bubbles: true }));
            if (sel.hasAttribute('data-submit') && input.form) input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit();
        }
    }

    function openSelect(sel) {
        var panel = $('[data-select-panel]', sel), button = $('[data-select-button]', sel);
        // Move the panel to <body> while open so tables with overflow can't clip it.
        panel._home = sel; document.body.appendChild(panel); panel._select = sel;
        open(panel, button, 'bottom-start');
        var search = $('[data-select-search]', panel);
        if (search) { search.value = ''; filter(panel, ''); search.focus(); }
        else { (panel.querySelector('[aria-selected=true]') || panel.querySelector('[role=option]')).focus(); }
    }

    function filter(panel, q) {
        q = q.toLowerCase(); var any = false;
        $$('[role=option]', panel).forEach(function (o) { var hit = (o.dataset.search || o.dataset.label).toLowerCase().indexOf(q) > -1; o.hidden = !hit; any = any || hit; });
        var empty = $('[data-select-empty]', panel); if (empty) empty.hidden = any;
    }

    function selectOf(el) { var p = el.closest('[data-select-panel]'); return p ? p._select : el.closest('[data-select]'); }

    // Return panels to their dropdowns when closed (keeps forms tidy).
    var origClose = close;
    close = function (focusBack) {
        var p = openPanel;
        origClose(focusBack);
        if (p && p._home && !p._home.contains(p)) p._home.appendChild(p);
    };

    /* ----------------------------------------------------------- clicks */

    document.addEventListener('click', function (e) {
        var t;
        if ((t = e.target.closest('[data-select-button]'))) {
            var sel = t.closest('[data-select]');
            if (openPanel && openPanel._select === sel) close(); else openSelect(sel);
            return;
        }
        if ((t = e.target.closest('[role=option]')) && selectOf(t)) { choose(selectOf(t), t); return; }
        if ((t = e.target.closest('[data-menu]'))) {
            var panel = $(t.dataset.menu);
            if (openPanel === panel) close(); else open(panel, t, t.dataset.placement || 'bottom-start');
            return;
        }
        if (openPanel && !openPanel.contains(e.target)) close();

        if ((t = e.target.closest('[data-sidebar-toggle]'))) {
            if (desktop.matches) {
                var collapsed = document.documentElement.dataset.sidebar !== 'collapsed';
                document.documentElement.dataset.sidebar = collapsed ? 'collapsed' : 'open';
                document.cookie = 'sidebar=' + (collapsed ? 'collapsed' : 'open') + ';path=/;max-age=31536000;samesite=lax';
            } else { toggleDrawer(true); }
            return;
        }
        if (e.target.closest('[data-close-drawer]')) { toggleDrawer(false); return; }
        if ((t = e.target.closest('[data-toggle]'))) {
            var target = $(t.dataset.toggle);
            if (target) { var show = target.hidden; target.hidden = !show; t.setAttribute('aria-expanded', String(show)); }
            return;
        }
        if ((t = e.target.closest('[data-dismiss]'))) { var box = t.closest('[data-dismissible]'); if (box) box.remove(); return; }
        if ((t = e.target.closest('[data-check-all]'))) {
            $$(t.dataset.checkAll + ' input[type=checkbox]:not(:disabled)').forEach(function (c) { c.checked = t.dataset.value === '1'; });
            return;
        }
        if ((t = e.target.closest('[data-passkey-login]'))) { e.preventDefault(); passkeyLogin(t); }
    });

    function toggleDrawer(show) {
        var d = $('#sidebar-drawer'); if (!d) return;
        d.hidden = !show; document.body.style.overflow = show ? 'hidden' : '';
        if (show) { var first = d.querySelector('a,button'); if (first) first.focus(); }
    }
    desktop.addEventListener('change', function () { toggleDrawer(false); });

    /* -------------------------------------------------------- keyboard */

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { if (openPanel) { close(true); return; } toggleDrawer(false); return; }

        var btn = e.target.closest('[data-select-button]');
        if (btn && (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openSelect(btn.closest('[data-select]')); return; }

        if (!openPanel || !openPanel.hasAttribute('data-select-panel')) return;
        var opts = options(openPanel), i = opts.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); (opts[i + 1] || opts[0]).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); (opts[i - 1] || opts[opts.length - 1]).focus(); }
        else if (e.key === 'Enter' && e.target.hasAttribute('data-select-search') && opts[0]) { e.preventDefault(); choose(openPanel._select, opts[0]); }
        else if (e.key === 'Tab') { close(); }
    });

    document.addEventListener('input', function (e) {
        if (e.target.hasAttribute('data-select-search')) filter(e.target.closest('[data-select-panel]'), e.target.value);
    });

    /* ------------------------------------------------ change / submit */

    function applyShowWhen() {
        $$('[data-show-when]').forEach(function (el) {
            var parts = el.dataset.showWhen.split('='), input = document.querySelector('[name="' + parts[0] + '"]');
            if (!input) return;
            var show = input.value === parts[1];
            el.hidden = !show;
            // the visible dashboard dropdown is the one that posts
            var hidden = el.querySelector('[data-select-input]');
            if (hidden && /^(default_dashboard|_dash_)/.test(hidden.name)) hidden.name = show ? 'default_dashboard' : '_dash_' + parts[1];
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-select-input]')) applyShowWhen();
        var input = e.target.closest('[data-preview]');
        if (input && input.files && input.files[0]) {
            var img = $(input.dataset.preview);
            if (img) { img.src = URL.createObjectURL(input.files[0]); img.hidden = false; var ph = input.closest('div').parentNode.querySelector('[data-preview-ph]'); if (ph) ph.hidden = true; }
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented) return;
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { e.preventDefault(); return; }
        if (form.hasAttribute('data-passkey-register')) { e.preventDefault(); passkeyRegister(form); return; }
        var btn = form.querySelector('button:not([type=button])');
        if (btn && !form.hasAttribute('data-no-busy')) setTimeout(function () { btn.disabled = true; }, 0);
    });

    /* ------------------------------------------------------- passkeys */

    function csrf() { var m = $('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function b64uToBuf(s) { var b = s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4); return Uint8Array.from(atob(b), function (c) { return c.charCodeAt(0); }).buffer; }
    function bufToB64u(buf) { if (!buf) return null; var s = ''; new Uint8Array(buf).forEach(function (b) { s += String.fromCharCode(b); }); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
    function post(url, body) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body || {}) })
            .then(function (res) { return res.json().catch(function () { return {}; }).then(function (d) { if (!res.ok) throw new Error(d.message || 'Error'); return d; }); });
    }
    function report(el, msg, ok) { if (!el) return; el.textContent = msg; el.hidden = !msg; el.style.color = ok ? '#047857' : ''; }
    function supported() { return !!(window.PublicKeyCredential && navigator.credentials); }
    function friendly(err, el) {
        if (err.name === 'NotAllowedError') return el.dataset.msgCancel;
        if (err.name === 'InvalidStateError') return el.dataset.msgExists || err.message;
        return err.message;
    }

    function passkeyLogin(btn) {
        var status = $('#passkey-status');
        if (!supported()) return report(status, btn.dataset.msgUnsupported);
        btn.disabled = true; report(status, '');
        post(btn.dataset.optionsUrl).then(function (o) { o.challenge = b64uToBuf(o.challenge); return navigator.credentials.get({ publicKey: o }); })
            .then(function (c) {
                var r = $('input[name="remember"]');
                return post(btn.dataset.loginUrl, { rawId: bufToB64u(c.rawId), clientDataJSON: bufToB64u(c.response.clientDataJSON), authenticatorData: bufToB64u(c.response.authenticatorData), signature: bufToB64u(c.response.signature), remember: r ? r.checked : false });
            })
            .then(function (r) { window.location.assign(r.redirect); })
            .catch(function (err) { report(status, friendly(err, btn)); btn.disabled = false; });
    }

    function passkeyRegister(form) {
        var status = $('[data-passkey-status]', form), btn = $('button[type="submit"]', form);
        if (!supported()) return report(status, form.dataset.msgUnsupported);
        btn.disabled = true; report(status, '');
        post(form.dataset.optionsUrl).then(function (o) {
            o.challenge = b64uToBuf(o.challenge); o.user.id = b64uToBuf(o.user.id);
            o.excludeCredentials = (o.excludeCredentials || []).map(function (c) { return { type: c.type, id: b64uToBuf(c.id) }; });
            return navigator.credentials.create({ publicKey: o });
        }).then(function (c) {
            var r = c.response, name = $('input[name="name"]', form);
            return post(form.action, { name: (name && name.value) || deviceName(), rawId: bufToB64u(c.rawId), clientDataJSON: bufToB64u(r.clientDataJSON),
                authenticatorData: bufToB64u(r.getAuthenticatorData ? r.getAuthenticatorData() : null), publicKey: bufToB64u(r.getPublicKey ? r.getPublicKey() : null),
                publicKeyAlgorithm: r.getPublicKeyAlgorithm ? r.getPublicKeyAlgorithm() : -7 });
        }).then(function () { report(status, form.dataset.msgOk, true); setTimeout(function () { location.reload(); }, 700); })
          .catch(function (err) { report(status, friendly(err, form)); btn.disabled = false; });
    }

    function deviceName() {
        var ua = navigator.userAgent;
        var os = /iPhone|iPad/.test(ua) ? 'iPhone/iPad' : /Android/.test(ua) ? 'Android' : /Mac/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : 'device';
        var br = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
        return br + ' · ' + os;
    }

    document.addEventListener('DOMContentLoaded', applyShowWhen);
})();
