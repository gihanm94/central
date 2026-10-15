/* Google Sheets sync form: radio panes, the spreadsheet picker (per Google account) and the tab list. */
(function () {
    'use strict';
    var form = document.querySelector('form[data-sheet-form]');
    if (!form) return;
    var $ = function (s) { return form.querySelector(s); };
    var account = $('[data-account]'), files = $('[data-files]'), note = $('[data-files-note]'), search = $('[data-file-search]'), tabs = $('[data-tabs]');
    var tab = $('[data-tab]'), table = $('[data-table]'), newName = $('[data-new-name]');

    function panes(name, value) {
        form.querySelectorAll('input[name="' + name + '"]').forEach(function (r) {
            var pane = form.querySelector('[data-pane-' + r.value + ']');
            if (pane) pane.hidden = r.value !== value;
        });
    }
    function checked(name) { var r = form.querySelector('input[name="' + name + '"]:checked'); return r ? r.value : ''; }
    function url(kind) { return form.dataset[kind + 'Url'].replace('__ID__', encodeURIComponent(account.value)); }
    function get(u) {
        return fetch(u, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || r.status); return j; }); });
    }

    var loaded = '', timer;
    function loadFiles() {
        if (checked('target') !== 'existing') return;
        var key = account.value + '|' + search.value.trim();
        if (key === loaded) return;
        loaded = key;
        var keep = files.value;
        note.textContent = '…';
        get(url('files') + '?q=' + encodeURIComponent(search.value.trim())).then(function (j) {
            var had = keep && files.querySelector('option[value="' + CSS.escape(keep) + '"]');
            var keepOpt = had ? had.cloneNode(true) : null;
            files.innerHTML = '';
            if (keepOpt && !j.files.some(function (f) { return f.id === keep; })) files.appendChild(keepOpt);
            j.files.forEach(function (f) {
                var o = document.createElement('option');
                o.value = f.id; o.textContent = f.name; o.selected = f.id === keep;
                files.appendChild(o);
            });
            note.textContent = j.files.length ? '' : (search.value ? 'No spreadsheet with that name.' : 'No spreadsheets in this Google account yet.');
            if (files.value) loadTabs();
        }).catch(function (e) { note.textContent = e.message; loaded = ''; });
    }
    function loadTabs() {
        tabs.innerHTML = '';
        if (checked('target') !== 'existing' || !files.value) return;
        get(url('tabs') + '?file=' + encodeURIComponent(files.value)).then(function (j) {
            (j.tabs || []).forEach(function (t) { var o = document.createElement('option'); o.value = t.title; tabs.appendChild(o); });
        }).catch(function () {});
    }

    form.addEventListener('change', function (e) {
        var t = e.target;
        if (t.matches('[data-target]')) { panes('target', t.value); loadFiles(); loadTabs(); }
        if (t.matches('[data-mode]')) {
            form.querySelector('[data-pane-daily]').hidden = t.value !== 'daily';
            form.querySelector('[data-pane-once]').hidden = t.value !== 'once';
        }
        if (t === account) { loaded = ''; loadFiles(); }
        if (t === files) loadTabs();
        if (t === table) {                                // follow the table name while the person has not typed their own
            var label = table.selectedOptions[0].dataset.label;
            if (tab.dataset.auto !== '0') tab.value = label;
            if (newName && newName.dataset.auto !== '0') newName.value = label;
        }
    });
    tab.addEventListener('input', function () { tab.dataset.auto = '0'; });
    if (newName) newName.addEventListener('input', function () { newName.dataset.auto = '0'; });
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(loadFiles, 350); });
    loadFiles();
})();
