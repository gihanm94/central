/* Calendar on FullCalendar (vendor/fullcalendar). Events come from /calendar/events (CRM activities + Google Calendar).
   Click a day or drag a time range → new-activity side sheet; click a CRM event → its edit sheet; Google events → small popover.
   Saving in the sheet reloads the page; the view and date are kept in localStorage. */
(function () {
    'use strict';
    var el = document.getElementById('cal'); if (!el || !window.FullCalendar) return;
    var C = JSON.parse(el.dataset.config), T = C.i18n, pop = document.getElementById('cal-pop'), KEY = 'cal-state-v2';
    var COLOR = { CALL: '#0284c7', MEETING: '#7c3aed', EMAIL: '#64748b', TASK: '#d97706', GOOGLE: '#059669' };
    var st = {}; try { st = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
    var narrow = window.matchMedia('(max-width: 640px)').matches;
    function p2(n) { return (n < 10 ? '0' : '') + n; }
    function local(d) { return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()) + 'T' + p2(d.getHours()) + ':' + p2(d.getMinutes()); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function newSheet(start) { if (C.canCreate) window.AcmeUI.sheet(C.create + '?start=' + encodeURIComponent(start), T.new, el); }

    var cal = new FullCalendar.Calendar(el, {
        initialView: st.view || (narrow ? 'listWeek' : 'dayGridMonth'), initialDate: st.date || undefined, height: '100%', firstDay: 1, nowIndicator: true, navLinks: true, dayMaxEvents: 3, selectable: !!C.canCreate, selectMirror: true,
        locale: C.lang === 'th' ? 'th' : 'en', slotMinTime: '06:00:00', slotMaxTime: '22:00:00', scrollTime: '08:00:00', expandRows: true, eventDisplay: 'block', displayEventEnd: false,
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        headerToolbar: { left: 'prev,next today', center: 'title', right: narrow ? 'listWeek,dayGridMonth' : 'dayGridMonth,timeGridWeek,timeGridDay,listMonth' },
        buttonText: { today: T.today, month: T.month, week: T.week, day: T.day, list: T.agenda }, allDayText: T.allday, noEventsText: T.nothing, moreLinkText: function (n) { return '+' + n + ' ' + T.more; },
        events: function (info, ok, fail) {
            var f = info.startStr.substr(0, 10), t = info.endStr.substr(0, 10);
            fetch(C.events + '?from=' + f + '&to=' + t + (C.mine ? '&mine=1' : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.text(); }).then(function (x) { var d = JSON.parse(x.slice(Math.max(0, x.indexOf('{'))));
                    ok((d.events || []).map(function (e) { return { id: e.id, title: e.title, start: e.start, end: e.end || undefined, allDay: !!e.all_day, backgroundColor: COLOR[e.type] || COLOR.TASK, borderColor: COLOR[e.type] || COLOR.TASK, classNames: e.status === 'DONE' ? ['is-done'] : [], extendedProps: e }; }));
                    if (d.warning && window.toast) window.toast(d.warning, 'error'); })
                .catch(function (e) { fail(e); if (window.toast) window.toast('Calendar: ' + e.message, 'error'); });
        },
        datesSet: function (i) { pop.classList.add('hidden'); try { localStorage.setItem(KEY, JSON.stringify({ view: i.view.type, date: cal.getDate().toISOString().substr(0, 10) })); } catch (e) {} },
        dateClick: function (i) { newSheet(i.allDay ? i.dateStr.substr(0, 10) + 'T09:00' : local(i.date)); },
        select: function (i) { if (i.view.type !== 'dayGridMonth') { newSheet(local(i.start)); } cal.unselect(); },
        eventClick: function (i) {
            i.jsEvent.preventDefault(); var o = i.event.extendedProps;
            if (o.source === 'crm') { window.AcmeUI.sheet(o.url, T.activity, i.el); return; }
            var h = '<div class="flex items-start justify-between gap-2"><b class="min-w-0 break-words">' + esc(o.title) + '</b><button type="button" data-pop-x class="text-steel">&times;</button></div>' +
                '<p class="mt-1 text-steel">' + esc(o.all_day ? T.allday : o.start.replace('T', ' ').substr(0, 16) + (o.end ? ' – ' + o.end.substr(11, 5) : '')) + '</p>' + (o.location ? '<p class="mt-1">' + esc(o.location) + '</p>' : '') +
                '<p class="mt-1 text-xs text-steel">' + esc(T.google) + '</p>' + (o.link ? '<a class="btn-secondary mt-3 !h-8" target="_blank" rel="noopener noreferrer" href="' + esc(o.link) + '">' + esc(T.open) + '</a>' : '');
            pop.innerHTML = h; pop.classList.remove('hidden');
            var b = i.el.getBoundingClientRect(); pop.style.top = Math.min(window.innerHeight - pop.offsetHeight - 8, Math.max(8, b.bottom + 4)) + 'px'; pop.style.left = Math.min(window.innerWidth - 296, Math.max(8, b.left)) + 'px';
        }
    });
    cal.render();
    document.addEventListener('click', function (e) { if (e.target.closest('[data-pop-x]') || (!e.target.closest('#cal-pop') && !e.target.closest('.fc-event'))) pop.classList.add('hidden'); });
    window.addEventListener('resize', function () { cal.updateSize(); });
})();
