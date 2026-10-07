/* "Request an account" dialog on the sign-in pages. */
(function () {
    'use strict';
    var dlg = document.getElementById('request-dialog'); if (!dlg) return;
    var form = document.getElementById('request-form'), body = form.querySelector('[data-request-body]'), done = form.querySelector('[data-request-done]'), send = form.querySelector('[data-request-send]');
    function errs(map) {
        Array.prototype.forEach.call(form.querySelectorAll('[data-err]'), function (p) { var m = map && map[p.dataset.err]; p.textContent = m ? [].concat(m)[0] : ''; p.classList.toggle('hidden', !m); });
        var first = map && Object.keys(map)[0]; var f = first && form.querySelector('[name="' + first + '"]'); if (f && f.focus) f.focus();
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-request-open]')) { e.preventDefault(); form.reset(); errs(null); body.classList.remove('hidden'); done.classList.add('hidden'); send.classList.remove('hidden'); dlg.showModal(); var f = form.querySelector('[name=employee_code]'); if (f) f.focus(); }
        else if (e.target.closest('[data-request-close]')) dlg.close();
        else if (e.target === dlg) dlg.close();
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault(); errs(null); send.disabled = true;
        fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name=_token]').value }, body: new FormData(form) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (x) {
                send.disabled = false;
                if (x.ok) { body.classList.add('hidden'); done.textContent = x.d.message || 'Sent.'; done.classList.remove('hidden'); send.classList.add('hidden'); return; }
                var map = x.d.errors || {}; if (!Object.keys(map).length) map = { _form: x.d.message || 'Could not send the request.' };
                errs(map);
            })
            .catch(function () { send.disabled = false; errs({ _form: 'Network error. Try again.' }); });
    });
})();
