<?php
declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Auth\Auth;

/**
 * Activity log. Descriptions are stored in English and re-rendered in the reader's language.
 *
 *   Activity::log('updated', 'task', $id, 'Fix belt', ['Updated :type ":label"', ['type' => 'task', 'label' => 'Fix belt']],
 *                 ['changes' => …], ownerId: $assignee);
 *
 * Record actions (created/updated/deleted/import/export/download) go through the Notifier,
 * which applies the company and personal notification switches.
 */
final class Activity
{
    public static function log(
        string $action,
        ?string $type = null,
        ?int $id = null,
        ?string $label = null,
        string|array|null $description = null,
        array $properties = [],
        ?int $ownerId = null,
        ?int $actorId = null,
        bool $notify = true,
        string $module = 'core',
    ): int {
        $actorId ??= Auth::id();
        [$key, $params] = is_array($description) ? [$description[0], $description[1] ?? []] : [$description ?? ucfirst($action), []];
        $text = ['key' => $key, 'params' => $params];
        $properties['_t'] = $text;

        $logId = (int) DB::insert('activity_logs', [
            'user_id'       => $actorId,
            'module'        => $module,
            'action'        => $action,
            'subject_type'  => $type,
            'subject_id'    => $id,
            'subject_label' => $label ? mb_substr($label, 0, 250) : null,
            'description'   => mb_substr(I18n::with('en', fn () => activity_render($text)), 0, 250),
            'properties'    => json_encode($properties, JSON_UNESCAPED_UNICODE),
            'ip_address'    => Request::ip(),
            'user_agent'    => Request::userAgent(),
        ]);

        if ($notify) {
            Notifier::event($module, $action, $text, $properties['changes'] ?? [], $actorId, $ownerId);
        }

        return $logId;
    }

    /** Field-by-field difference for "updated" entries. */
    public static function diff(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $f) {
            if (! array_key_exists($f, $after)) {
                continue;
            }
            $old = self::flat($before[$f] ?? null);
            $new = self::flat($after[$f]);
            if ((string) $old !== (string) $new) {
                $changes[$f] = ['from' => $old, 'to' => $new];
            }
        }

        return $changes;
    }

    private static function flat(mixed $v): mixed
    {
        return is_bool($v) ? ($v ? 'yes' : 'no') : ($v === 't' ? 'yes' : ($v === 'f' ? 'no' : $v));
    }
}
