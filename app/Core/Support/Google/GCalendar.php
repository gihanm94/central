<?php
declare(strict_types=1);

namespace App\Core\Support\Google;

use App\Core\Support\DB;

/** Google Calendar events of one person (primary calendar). */
final class GCalendar
{
    private const BASE = '/calendar/v3/calendars/primary/events';

    /** Events between two moments (ISO), cached briefly so paging through months stays quick. @return array<int, array> */
    public static function events(int $userId, string $from, string $to): array
    {
        $cache = BASE_PATH.'/storage/cache/gcal-'.$userId.'-'.md5($from.$to).'.json';
        if (is_file($cache) && time() - filemtime($cache) < 90) { return json_decode((string) file_get_contents($cache), true) ?: []; }
        $r = Google::api($userId, 'GET', self::BASE, ['timeMin' => $from, 'timeMax' => $to, 'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 250]);
        if ($r['status'] >= 400) { throw new \RuntimeException(($r['body']['error']['message'] ?? 'Calendar error').' ('.$r['status'].')'); }
        $out = [];
        foreach ($r['body']['items'] ?? [] as $e) {
            if (($e['status'] ?? '') === 'cancelled') { continue; }
            $allDay = isset($e['start']['date']);
            $out[] = ['id' => (string) $e['id'], 'title' => (string) ($e['summary'] ?? '(no title)'), 'start' => (string) ($e['start']['dateTime'] ?? $e['start']['date'] ?? ''), 'end' => (string) ($e['end']['dateTime'] ?? $e['end']['date'] ?? ''),
                'all_day' => $allDay, 'link' => (string) ($e['htmlLink'] ?? ''), 'location' => (string) ($e['location'] ?? ''), 'source' => 'google'];
        }
        @file_put_contents($cache, json_encode($out));

        return $out;
    }

    private static function forget(int $userId): void { foreach (glob(BASE_PATH.'/storage/cache/gcal-'.$userId.'-*.json') ?: [] as $f) { @unlink($f); } }

    /** Create or update the event of a CRM activity on its owner's calendar. Never throws: the activity is saved either way. */
    public static function syncActivity(int $activityId): void
    {
        try {
            $a = DB::first('SELECT * FROM activities WHERE id = ?', [$activityId], 'crm');
            if (! $a) { return; }
            $uid = (int) ($a['owner_id'] ?: $a['created_by']);
            if (! $uid || ! Google::connected($uid)) { return; }
            if ($a['deleted_at'] || $a['status'] === 'CANCELLED' || ! $a['start_at']) { self::removeActivity($a); return; }
            $mins = (int) ($a['activity_type'] === 'MEETING' ? ($a['meeting_duration'] ?: 60) : ($a['call_duration'] ?: 30));
            $start = strtotime((string) $a['start_at']);
            $label = ['CALL' => '📞', 'MEETING' => '👥', 'EMAIL' => '✉️', 'TASK' => '✅'][$a['activity_type']] ?? '';
            $body = [
                'summary' => trim($label.' '.($a['status'] === 'DONE' ? '✓ ' : '').$a['topic']),
                'description' => trim(strip_tags((string) $a['description'])."\n\n".url('/crm/activities/'.$a['id'])),
                'location' => (string) ($a['meeting_location'] ?? ''),
                'start' => ['dateTime' => date('c', $start), 'timeZone' => (string) config('app.timezone', 'Asia/Bangkok')],
                'end' => ['dateTime' => date('c', $start + $mins * 60), 'timeZone' => (string) config('app.timezone', 'Asia/Bangkok')],
                'reminders' => filter_var($a['notify_me'], FILTER_VALIDATE_BOOL) ? ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => (int) $a['notify_before']]]] : ['useDefault' => true],
                'source' => ['title' => (string) config('app.name', 'CRM'), 'url' => url('/crm/activities/'.$a['id'])],
            ];
            if ($a['google_event_id'] && (int) $a['google_user_id'] === $uid) {
                $r = Google::api($uid, 'PATCH', self::BASE.'/'.rawurlencode((string) $a['google_event_id']), [], $body);
                if ($r['status'] < 400) { self::forget($uid); return; }
            }
            $r = Google::api($uid, 'POST', self::BASE, [], $body);
            if ($r['status'] < 400 && ! empty($r['body']['id'])) {
                DB::exec('UPDATE activities SET google_event_id = ?, google_user_id = ? WHERE id = ?', [$r['body']['id'], $uid, $activityId], 'crm');
                self::forget($uid);
            } else { error_log('[gcal] activity '.$activityId.' not added: '.json_encode($r['body'])); }
        } catch (\Throwable $e) { error_log('[gcal] activity '.$activityId.': '.$e->getMessage()); }
    }

    public static function removeActivity(array $a): void
    {
        try {
            if (empty($a['google_event_id']) || empty($a['google_user_id'])) { return; }
            Google::api((int) $a['google_user_id'], 'DELETE', self::BASE.'/'.rawurlencode((string) $a['google_event_id']));
            DB::exec('UPDATE activities SET google_event_id = NULL, google_user_id = NULL WHERE id = ?', [$a['id']], 'crm');
            self::forget((int) $a['google_user_id']);
        } catch (\Throwable $e) { error_log('[gcal] remove: '.$e->getMessage()); }
    }
}
