/*
 | KPI submissions.
 |   form[data-kpi-form]   year / month change reloads the KPI list for that month, shows the chosen KPI's criteria,
 |                         lists the attached files (drag and drop too), and sends only once.
 |   [data-kpi-group]      person and month groups remember being collapsed (this browser only).
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-kpi-form]');
    if (form) {
        var val = function (n) { var i = form.querySelector('[name="' + n + '"]'); return i ? i.value : ''; };
        var criteria = {};
        try { criteria = JSON.parse(form.getAttribute('data-kpi-criteria') || '{}'); } catch (e) {}
        var out = form.querySelector('[data-kpi-criteria-out]');
        var showCriteria = function () { if (out) out.textContent = criteria[val('kpi_id')] || '—'; };
        showCriteria();

        form.addEventListener('change', function (e) {
            var n = e.target.name;
            if (n === 'year' || n === 'month') {
                var q = new URLSearchParams({ year: val('year'), month: val('month') });
                if (val('approver_id')) q.set('approver', val('approver_id'));
                window.location = form.getAttribute('data-kpi-url') + '?' + q.toString();
            }
            if (n === 'kpi_id') showCriteria();
            if (e.target.matches('[data-kpi-files]')) listFiles();
        });

        var files = form.querySelector('[data-kpi-files]'), list = form.querySelector('[data-kpi-file-list]'), drop = form.querySelector('[data-kpi-drop]');
        var size = function (b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; };
        function listFiles() {
            list.innerHTML = '';
            Array.prototype.forEach.call(files.files, function (f) {
                var li = document.createElement('li');
                li.className = 'flex items-center justify-between gap-3 rounded-md bg-mist px-3 py-1.5';
                var name = document.createElement('span'); name.className = 'truncate'; name.textContent = f.name;
                var s = document.createElement('span'); s.className = 'shrink-0 text-xs text-steel tabular-nums'; s.textContent = size(f.size);
                li.appendChild(name); li.appendChild(s); list.appendChild(li);
            });
        }
        if (drop && files) {
            ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('border-signal-600'); }); });
            ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('border-signal-600'); }); });
            drop.addEventListener('drop', function (e) {
                e.preventDefault();
                if (!e.dataTransfer || !e.dataTransfer.files.length) return;
                var dt = new DataTransfer();
                Array.prototype.forEach.call(files.files, function (f) { dt.items.add(f); });
                Array.prototype.forEach.call(e.dataTransfer.files, function (f) { dt.items.add(f); });
                files.files = dt.files;
                listFiles();
            });
        }

        // One click, one submission: keep the chosen button's value, then lock both buttons.
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            if (form.dataset.sent) { e.preventDefault(); return; }
            form.dataset.sent = '1';
            var b = e.submitter;
            if (b && b.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = b.name; h.value = b.value; form.appendChild(h); }
            form.querySelectorAll('button[name=next]').forEach(function (x) { x.disabled = true; });
        });
    }

    var KEY = 'kpi-collapsed';
    var closed = {};
    try { closed = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) {}
    var target = location.hash.length > 1 ? document.getElementById(location.hash.slice(1)) : null;
    document.querySelectorAll('[data-kpi-group]').forEach(function (d) {
        var id = location.pathname + '|' + new URLSearchParams(location.search).get('year') + '|' + d.id;
        if (closed[id] && !(target && d.contains(target))) d.open = false;     // a link into a group opens it
        d.addEventListener('toggle', function () {
            if (d.open) delete closed[id]; else closed[id] = 1;
            try { localStorage.setItem(KEY, JSON.stringify(closed)); } catch (e) {}
        });
    });
})();
