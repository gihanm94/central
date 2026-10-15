<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;

/** CRM activities for the shared /calendar page (the 'calendar.events' hook in module.php). */
final class CalendarFeed
{
    /** @return array{events: list<array>, linked: array<string,bool>} linked = Google event ids already shown as a CRM activity */
    public static function events(CurrentUser $u, string $from, string $to, bool $mine): array
    {
        $events = []; $linked = [];
        if (! can('crm_activities', 'view')) {
            return ['events' => $events, 'linked' => $linked];
        }
        $s = Access::visible($u, 't', 'activity');
        $where = $s['sql'].' AND t.deleted_at IS NULL AND t.start_at IS NOT NULL AND t.start_at >= ?::date AND t.start_at < (?::date + 1)';
        $params = array_merge($s['params'], [$from, $to]);
        if ($mine) { $where .= ' AND t.owner_id = ?'; $params[] = $u->id; }
        $rows = DB::select("SELECT t.id, t.topic, t.activity_type, t.status, t.start_at, t.call_duration, t.meeting_duration, t.google_event_id, t.google_user_id, t.owner_id FROM activities t WHERE $where ORDER BY t.start_at LIMIT 1000", $params, 'crm');
        foreach ($rows as $a) {
            $start = strtotime((string) $a['start_at']);
            $mins = (int) ($a['activity_type'] === 'MEETING' ? ($a['meeting_duration'] ?: 60) : ($a['call_duration'] ?: 30));
            $events[] = ['id' => 'a'.$a['id'], 'title' => (string) $a['topic'], 'start' => date('Y-m-d\TH:i:s', $start), 'end' => date('Y-m-d\TH:i:s', $start + $mins * 60), 'all_day' => false,
                'source' => 'crm', 'type' => $a['activity_type'], 'status' => $a['status'], 'url' => '/crm/activities/'.$a['id'].'/edit', 'show' => '/crm/activities/'.$a['id']];
            if ($a['google_event_id'] && (int) $a['google_user_id'] === $u->id) { $linked[(string) $a['google_event_id']] = true; }
        }

        return ['events' => $events, 'linked' => $linked];
    }
}
