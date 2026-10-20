/*
 | PDF preview drawn with pdf.js ([data-pdf-preview="url"]): works where the browser has no PDF viewer of its own
 | (phones, some locked-down browsers). Pages are drawn when they scroll into view.
 */
(function () {
    'use strict';
    document.querySelectorAll('[data-pdf-preview]').forEach(function (box) {
        if (!window.pdfjsLib) return;
        pdfjsLib.GlobalWorkerOptions.workerSrc = box.dataset.worker;
        var status = box.querySelector('[data-pdf-status]');
        pdfjsLib.getDocument({ url: box.dataset.pdfPreview, withCredentials: true }).promise.then(function (pdf) {
            if (status) status.remove();
            var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
                es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); draw(e.target); } });
            }, { root: box, rootMargin: '400px' }) : null;
            function draw(holder) {
                pdf.getPage(+holder.dataset.n).then(function (page) {
                    var w = holder.clientWidth, vp1 = page.getViewport({ scale: 1 }), dpr = Math.min(window.devicePixelRatio || 1, 2);
                    var vp = page.getViewport({ scale: w / vp1.width * dpr }), c = document.createElement('canvas');
                    c.width = Math.floor(vp.width); c.height = Math.floor(vp.height); c.className = 'block h-auto w-full';
                    holder.style.height = ''; holder.appendChild(c);
                    page.render({ canvasContext: c.getContext('2d'), viewport: vp });
                });
            }
            for (var i = 1; i <= Math.min(pdf.numPages, 300); i++) {
                var h = document.createElement('div');
                h.className = 'mx-auto w-full max-w-3xl bg-white shadow ring-1 ring-graphite-900/10';
                h.dataset.n = i; h.style.height = '400px';
                box.appendChild(h);
                if (io) io.observe(h); else draw(h);
            }
        }).catch(function () { if (status) status.textContent = box.dataset.lFailed || 'The preview could not be shown.'; });
    });
})();
