<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Google\GCalendar;
use App\Core\Support\Google\Google;
use App\Modules\CRM\Support\Access;

/** One calendar: CRM activities (what you may see) + your Google Calendar. /calendar for everyone, /crm/calendar is the same page inside CRM. */
class CalendarController extends Controller
{
    public function index(): string
    {
        $u = $this->user();
        $crm = can('crm_activities', 'view');

        return view('calendar/index', ['title' => __('Calendar'), 'crm' => $crm, 'canCreate' => $crm && can('crm_activities', 'create'),
            'google' => Google::connected($u->id), 'configured' => Google::configured(), 'inCrm' => str_starts_with(Request::path(), '/crm'),
            'mine' => Request::query('mine') === '1']);
    }

    public function events(): never
    {
        $u = $this->user();
        $from = (string) Request::query('from'); $to = (string) Request::query('to');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $to)) { json_response(['events' => []], 422); }
        $from = substr($from, 0, 10); $to = substr($to, 0, 10);
        $events = []; $linked = []; $warn = null;

        if (can('crm_activities', 'view')) {
            $s = Access::visible($u, 't', 'activity');
            $where = $s['sql'].' AND t.deleted_at IS NULL AND t.start_at IS NOT NULL AND t.start_at >= ?::date AND t.start_at < (?::date + 1)';
            $params = array_merge($s['params'], [$from, $to]);
            if (Request::query('mine') === '1') { $where .= ' AND t.owner_id = ?'; $params[] = $u->id; }
            $rows = DB::select("SELECT t.id, t.topic, t.activity_type, t.status, t.start_at, t.call_duration, t.meeting_duration, t.google_event_id, t.google_user_id, t.owner_id FROM activities t WHERE $where ORDER BY t.start_at LIMIT 1000", $params, 'crm');
            foreach ($rows as $a) {
                $start = strtotime((string) $a['start_at']);
                $mins = (int) ($a['activity_type'] === 'MEETING' ? ($a['meeting_duration'] ?: 60) : ($a['call_duration'] ?: 30));
                $events[] = ['id' => 'a'.$a['id'], 'title' => (string) $a['topic'], 'start' => date('Y-m-d\TH:i:s', $start), 'end' => date('Y-m-d\TH:i:s', $start + $mins * 60), 'all_day' => false,
                    'source' => 'crm', 'type' => $a['activity_type'], 'status' => $a['status'], 'url' => '/crm/activities/'.$a['id'].'/edit', 'show' => '/crm/activities/'.$a['id']];
                if ($a['google_event_id'] && (int) $a['google_user_id'] === $u->id) { $linked[(string) $a['google_event_id']] = true; }
            }
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
