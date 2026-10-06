/* Accounting overview charts (Chart.js, vendored). One accent colour (the brand red) for single series; fixed order for categories; text in ink, never in series colours. */
(function () {
    'use strict';
    if (!window.Chart) return;
    var D = JSON.parse(document.getElementById('dash-data').textContent), L = D.labels;
    var css = getComputedStyle(document.documentElement), v = function (n, d) { return (css.getPropertyValue(n) || '').trim() || d; };
    var RED = v('--color-signal-600', '#c8102e'), INK = v('--color-graphite-700', '#3a3c40'), MUTED = '#8a8f98', GRID = 'rgba(23,24,27,.07)';
    var GREEN = '#059669', AMBER = '#d97706', SKY = '#0284c7', GREY = '#9aa0a9', PALE = 'rgba(200,16,46,.28)';
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily; Chart.defaults.font.size = 12; Chart.defaults.color = MUTED;
    Chart.defaults.plugins.legend.labels.usePointStyle = true; Chart.defaults.plugins.legend.labels.boxWidth = 8; Chart.defaults.plugins.legend.labels.color = INK;
    Chart.defaults.plugins.tooltip.backgroundColor = '#17181b'; Chart.defaults.plugins.tooltip.padding = 10; Chart.defaults.plugins.tooltip.cornerRadius = 8;
    var thb = function (n) { return '฿' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 }); };
    var compact = function (n) { var a = Math.abs(n); return (n < 0 ? '-' : '') + (a >= 1e6 ? (a / 1e6).toFixed(a >= 1e7 ? 0 : 1) + 'M' : a >= 1e3 ? Math.round(a / 1e3) + 'K' : a); };
    var num = function (r) { return r.map(function (x) { return Number(x.v); }); };
    var short = function (s, n) { s = String(s); return s.length > n ? s.slice(0, n - 1) + '…' : s; };
    function mk(id, cfg) {
        var el = document.getElementById(id); if (!el) return;
        var ds = (cfg.data.datasets || []).reduce(function (a, d) { return a.concat(d.data); }, []);
        if (cfg.type !== 'doughnut' && cfg.options && cfg.options.scales && ds.every(function (x) { return !Number(x); })) { (cfg.options.scales.y || cfg.options.scales.x).suggestedMax = 100; }   // an empty chart should not show 0 … 1
        new Chart(el, cfg);
    }
    var base = function (extra) { return Object.assign({ responsive: true, maintainAspectRatio: false, animation: { duration: 400 }, plugins: { legend: { display: false } } }, extra || {}); };
    var yAxis = { grid: { color: GRID }, border: { display: false }, ticks: { callback: compact, maxTicksLimit: 5 } };
    var xAxis = { grid: { display: false }, border: { color: GRID } };
    var bars = function (labels, data, color) { return { labels: labels, datasets: [{ data: data, backgroundColor: color || RED, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 44 }] }; };
    var hbars = function (rows, name) { return { type: 'bar', data: { labels: rows.map(function (r) { return short(r.name, 26); }), datasets: [{ data: num(rows), backgroundColor: RED, borderRadius: { topRight: 4, bottomRight: 4 }, borderSkipped: 'left', maxBarThickness: 18 }] },
        options: base({ indexAxis: 'y', scales: { x: yAxis, y: { grid: { display: false }, border: { display: false }, ticks: { color: INK } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { title: function (i) { return rows[i[0].dataIndex].name; }, label: function (c) { return thb(c.raw); } } } } }) }; };

    if (D.by_year) mk('ch-year', { type: 'bar', data: bars(D.by_year.map(function (r) { return r.y; }), num(D.by_year)), options: base({ scales: { x: xAxis, y: yAxis }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return thb(c.raw); } } } } }) });
    if (D.month_cur) mk('ch-month', { type: 'bar', data: { labels: L.months, datasets: [
        { label: L.prev, data: D.month_prev, backgroundColor: 'rgba(23,24,27,.16)', borderRadius: { topLeft: 3, topRight: 3 }, borderSkipped: 'bottom', maxBarThickness: 16 },
        { label: L.month + ' (' + D.year + ')', data: D.month_cur, backgroundColor: RED, borderRadius: { topLeft: 3, topRight: 3 }, borderSkipped: 'bottom', maxBarThickness: 16 }] },
        options: base({ scales: { x: xAxis, y: yAxis }, plugins: { legend: { display: true, position: 'bottom' }, tooltip: { mode: 'index', callbacks: { label: function (c) { return c.dataset.label + ': ' + thb(c.raw); } } } } }) });
    if (D.customers) mk('ch-cust', hbars(D.customers));
    if (D.sellers) mk('ch-seller', hbars(D.sellers));
    if (D.products) mk('ch-prod', hbars(D.products));
    if (D.aging) {
        var order = ['0 not due', '1 1–30', '2 31–60', '3 61–90', '4 90+'], map = {}; D.aging.forEach(function (r) { map[r.b] = Number(r.v); });
        mk('ch-aging', { type: 'bar', data: { labels: order.map(function (k, i) { return i === 0 ? L.notdue : k.slice(2) + ' ' + L.days; }), datasets: [{ data: order.map(function (k) { return map[k] || 0; }),
            backgroundColor: [GREY, '#e0b24a', '#e08a3a', '#d9533a', RED], borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 52 }] },
            options: base({ scales: { x: xAxis, y: yAxis }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return thb(c.raw); } } } } }) });
    }
    if (D.orders_month) mk('ch-orders', { type: 'line', data: { labels: D.orders_month.map(function (r) { return r.ym; }), datasets: [{ label: L.orders, data: D.orders_month.map(function (r) { return r.n; }), borderColor: RED, backgroundColor: PALE, fill: true, tension: .3, pointRadius: 3, pointBackgroundColor: '#fff', pointBorderColor: RED, pointBorderWidth: 2, borderWidth: 2 }] },
        options: base({ scales: { x: xAxis, y: Object.assign({}, yAxis, { beginAtZero: true }) }, interaction: { intersect: false, mode: 'index' } }) });
    if (D.inet && D.inet.total !== undefined) {
        var i = D.inet, vals = [i.signed, i.processing, i.waiting, i.not_generated].map(Number);
        mk('ch-inet', { type: 'doughnut', data: { labels: L.inet, datasets: [{ data: vals, backgroundColor: [GREEN, AMBER, SKY, GREY], borderColor: '#fff', borderWidth: 2 }] }, options: base({ cutout: '66%', plugins: { legend: { display: true, position: 'bottom' } } }) });
    }
    if (D.billing_status) {
        var bs = D.billing_status, key = ['pending', 'overdue', 'paid', 'complete'], col = { pending: GREY, overdue: RED, paid: SKY, complete: GREEN };
        var present = key.filter(function (k) { return bs.some(function (r) { return r.s === k; }); });
        mk('ch-bstatus', { type: 'doughnut', data: { labels: present.map(function (k) { return L.bill[k]; }), datasets: [{ data: present.map(function (k) { return bs.filter(function (r) { return r.s === k; })[0].n; }), backgroundColor: present.map(function (k) { return col[k]; }), borderColor: '#fff', borderWidth: 2 }] },
            options: base({ cutout: '66%', plugins: { legend: { display: true, position: 'bottom' }, tooltip: { callbacks: { afterLabel: function (c) { return thb(bs.filter(function (r) { return r.s === present[c.dataIndex]; })[0].v); } } } } }) });
    }
    if (D.billing_month) mk('ch-bmonth', { type: 'bar', data: bars(D.billing_month.map(function (r) { return r.ym; }), num(D.billing_month), RED), options: base({ scales: { x: xAxis, y: yAxis }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return thb(c.raw); } } } } }) });
})();
