<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Support\DB;
use App\Core\Support\Notifier;

/**
 * Sends the reminder of a planned activity (bell, e-mail, Lark — whatever the company and the person left on)
 * once its reminder time has come. Called by the bell's once-a-minute check while people are using the app,
 * and by `php bin/reminders.php` from cron so it also works when nobody is signed in.
 */
final class Reminders
{
    public static function tick(bool $force = false): int
    {
        $stamp = BASE_PATH.'/storage/cache/reminders.stamp';
        if (! $force && is_file($stamp) && time() - (int) @filemtime($stamp) < 55) {
            return 0;
        }
        @touch($stamp);

        $due = DB::select("SELECT t.id, t.topic, t.activity_type, t.start_at, t.owner_id, t.created_by, l.name_en AS lead_name
              FROM activities t LEFT JOIN leads l ON l.id = t.lead_id
             WHERE t.deleted_at IS NULL AND t.status = 'PLANNED' AND t.notify_me AND t.reminder_sent_at IS NULL AND t.start_at IS NOT NULL
               AND t.start_at - (t.notify_before * interval '1 minute') <= now() AND t.start_at > now() - interval '1 day'
             ORDER BY t.start_at LIMIT 100", [], 'crm');

        foreach ($due as $a) {
            $to = (int) ($a['owner_id'] ?: $a['created_by']);
            DB::exec('UPDATE activities SET reminder_sent_at = now() WHERE id = ?', [$a['id']], 'crm');
            if (! $to) {
                continue;
            }
            Notifier::deliver('crm', 'reminder', $to,
                ['key' => 'Reminder: :topic', 'params' => ['topic' => $a['topic']]],
                ['key' => ':type at :when:lead', 'params' => [
                    'type' => Catalog::ACTIVITY_TYPES[$a['activity_type']] ?? $a['activity_type'],
                    'when' => date('d M Y H:i', strtotime((string) $a['start_at'])),
                    'lead' => $a['lead_name'] ? ' · '.$a['lead_name'] : '',
                ]],
                url('/crm/activities/'.$a['id']));
        }

        return count($due);
    }
}
