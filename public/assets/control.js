/* Control room: sign-ins by department (stacked, department colours) and busiest hours. Also: typing dates switches to a custom range. */
(function () {
    'use strict';
    var f = document.getElementById('ctl-filter');
    if (f) {
        var rng = f.querySelector('[data-range]');
        Array.prototype.forEach.call(f.querySelectorAll('[data-custom]'), function (i) { i.addEventListener('change', function () { rng.value = 'custom'; f.submit(); }); });
    }
    var el = document.getElementById('ctl-data'); if (!el || !window.Chart) return;
    var D = JSON.parse(el.textContent), css = getComputedStyle(document.documentElement);
    var INK = (css.getPropertyValue('--color-graphite-700') || '#3a3c40').trim(), MUTED = '#8a8f98', GRID = 'rgba(23,24,27,.07)', RED = (css.getPropertyValue('--color-signal-600') || '#d11a2a').trim();
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily; Chart.defaults.font.size = 12; Chart.defaults.color = MUTED;
    Chart.defaults.plugins.tooltip.backgroundColor = '#17181b'; Chart.defaults.plugins.tooltip.padding = 10; Chart.defaults.plugins.tooltip.cornerRadius = 8;
    var fmt = function (iso) { var d = new Date(iso + 'T00:00:00'); return D.unit === 'month' ? d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' }) : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }); };
    var labels = D.buckets.map(fmt);

    var stack = document.getElementById('ctl-stack'), chart;
    if (stack && D.series.length) {
        chart = new Chart(stack, {
            type: 'bar',
            data: { labels: labels, datasets: D.series.map(function (s) { return { label: s.name, data: s.data, backgroundColor: s.color, borderWidth: 0, borderRadius: 2, maxBarThickness: 34, stack: 'a' }; }) },
            options: { responsive: true, maintainAspectRatio: false, animation: { duration: 350 }, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: { itemSort: function (a, b) { return b.raw - a.raw; }, filter: function (i) { return i.raw > 0; }, callbacks: { footer: function (items) { var t = items.reduce(function (a, i) { return a + i.raw; }, 0); return 'Σ ' + t; } } } },
                scales: { x: { stacked: true, grid: { display: false }, border: { color: GRID }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 16 } }, y: { stacked: true, beginAtZero: true, grid: { color: GRID }, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 } } } }
        });
        document.getElementById('ctl-legend').addEventListener('click', function (e) {
            var b = e.target.closest('[data-ds]'); if (!b) return;
            var i = +b.dataset.ds, show = !chart.isDatasetVisible(i); chart.setDatasetVisibility(i, show); b.setAttribute('aria-pressed', show ? 'true' : 'false'); b.style.opacity = show ? 1 : .4; chart.update();
        });
    }

    var h = document.getElementById('ctl-hours');
    if (h) {
        var peak = Math.max.apply(null, D.hours);
        new Chart(h, { type: 'bar', data: { labels: D.hours.map(function (_, i) { return (i < 10 ? '0' : '') + i; }), datasets: [{ data: D.hours, backgroundColor: D.hours.map(function (v) { return v && v === peak ? RED : 'rgba(23,24,27,.22)'; }), borderRadius: 2, maxBarThickness: 14 }] },
            options: { responsive: true, maintainAspectRatio: false, animation: { duration: 350 }, plugins: { legend: { display: false }, tooltip: { callbacks: { title: function (i) { return i[0].label + ':00'; }, label: function (c) { return c.raw + ' ' + D.label.signins; } } } },
                scales: { x: { grid: { display: false }, border: { color: GRID }, ticks: { maxRotation: 0, callback: function (v, i) { return i % 3 === 0 ? this.getLabelForValue(v) : ''; } } }, y: { beginAtZero: true, grid: { color: GRID }, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 4 } } } } });
    }
})();
