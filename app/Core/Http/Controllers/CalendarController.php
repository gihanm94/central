<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Google\GCalendar;
use App\Core\Support\Google\Google;
use App\Core\Support\Modules;

/** One calendar: events from the modules' 'calendar.events' hooks (CRM activities you may see) + your Google Calendar. /calendar for everyone, /crm/calendar is the same page inside CRM. */
class CalendarController extends Controller
{
    public function index(): string
    {
        $u = $this->user();
        $crm = Modules::usable('crm') && can('crm_activities', 'view');

        return view('calendar/index', ['title' => __('Calendar'), 'crm' => $crm, 'canCreate' => $crm && can('crm_activities', 'create'),
            'google' => Google::connected($u->id), 'configured' => Google::configured(), 'lost' => Google::connected($u->id) ? null : (Google::connection($u->id)['last_error'] ?? null), 'inCrm' => str_starts_with(Request::path(), '/crm'),
            'mine' => Request::query('mine') === '1']);
    }

    public function events(): never
    {
        $u = $this->user();
        $from = (string) Request::query('from'); $to = (string) Request::query('to');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $to)) { json_response(['events' => []], 422); }
        $from = substr($from, 0, 10); $to = substr($to, 0, 10);
        $events = []; $linked = []; $warn = null;

        foreach (Modules::hook('calendar.events', [$u, $from, $to, Request::query('mine') === '1']) as $feed) {
            $events = array_merge($events, $feed['events'] ?? []);
            $linked += $feed['linked'] ?? [];
        }
        if (Google::connected($u->id)) {
            try {
                $tz = new \DateTimeZone((string) config('app.timezone', 'Asia/Bangkok'));
                $iso = fn (string $d) => (new \DateTimeImmutable($d, $tz))->format('c');
                foreach (GCalendar::events($u->id, $iso($from.' 00:00:00'), $iso($to.' 00:00:00')) as $g) {
                    if (isset($linked[$g['id']])) { continue; }       // already shown as the CRM activity it came from
                    $g['id'] = 'g'.$g['id']; $g['type'] = 'GOOGLE';
                    if (! $g['all_day']) { $g['start'] = date('Y-m-d\TH:i:s', strtotime($g['start'])); $g['end'] = $g['end'] ? date('Y-m-d\TH:i:s', strtotime($g['end'])) : ''; }
                    $events[] = $g;
                }
            } catch (\Throwable $e) { $warn = $e->getMessage(); }
        }

        json_response(['events' => $events, 'warning' => $warn]);
    }
}
