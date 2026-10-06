<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Request;

/** The bell in the top bar and the full list of a person's notifications. */
class NotificationController extends Controller
{
    public static function unread(int $userId): int
    {
        return (int) DB::scalar('SELECT count(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    public static function latest(int $userId, int $limit = 8): array
    {
        return DB::select('SELECT n.*, a.name AS actor_name FROM notifications n LEFT JOIN users a ON a.id = n.actor_id
                            WHERE n.user_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT '.(int) $limit, [$userId]);
    }

    public function index(): string
    {
        $u      = $this->user();
        $only   = Request::query('show') === 'unread';
        $page   = max(1, (int) Request::query('page', 1));
        $per    = 20;
        $where  = 'n.user_id = ?'.($only ? ' AND n.read_at IS NULL' : '');
        $total  = (int) DB::scalar("SELECT count(*) FROM notifications n WHERE {$where}", [$u->id]);
        $rows   = DB::select("SELECT n.*, a.name AS actor_name FROM notifications n LEFT JOIN users a ON a.id = n.actor_id WHERE {$where}
                              ORDER BY n.created_at DESC, n.id DESC LIMIT {$per} OFFSET ".(($page - 1) * $per), [$u->id]);

        return view('notifications/index', ['title' => __('Notifications'), 'rows' => $rows, 'total' => $total, 'perPage' => $per, 'page' => $page, 'only' => $only, 'unread' => self::unread($u->id)]);
    }

    /** Polled every minute by the page: unread count + the dropdown's list. Also fires due activity reminders. */
    public function feed(): never
    {
        $u = $this->user();
        if (config('modules.crm.enabled') && class_exists(\App\Modules\CRM\Support\Reminders::class)) {
            \App\Modules\CRM\Support\Reminders::tick();
        }
        json_response(['unread' => self::unread($u->id), 'html' => partial('partials/notification-list', ['items' => self::latest($u->id)])]);
    }

    public function open(int $id): never
    {
        $n = DB::first('SELECT url FROM notifications WHERE id = ? AND user_id = ?', [$id, $this->user()->id]) ?? abort(404);
        DB::exec('UPDATE notifications SET read_at = COALESCE(read_at, now()) WHERE id = ?', [$id]);
        redirect($n['url'] ?: '/notifications');
    }

    public function readAll(): never
    {
        DB::exec('UPDATE notifications SET read_at = now() WHERE user_id = ? AND read_at IS NULL', [$this->user()->id]);
        if (Request::isJson()) {
            json_response(['unread' => 0]);
        }
        back('success', __('All notifications marked as read.'));
    }
}
