<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\SystemLogs;

/** Administrator → System logs: the PHP / application error log and the other server logs, with full error details. Administrators only. */
class SystemLogController extends Controller
{
    private const PER_PAGE = 40;

    private function gate(): void
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can open the system logs.'));
    }

    /** @return array{0: string, 1: array} the chosen file name and its info */
    private function file(array $files): array
    {
        $name = (string) Request::query('file', Request::input('file', 'php-error.log'));
        if (! isset($files[$name])) {
            $name = isset($files['php-error.log']) ? 'php-error.log' : (string) array_key_first($files);
        }

        return [$name, $files[$name] ?? ['path' => '', 'size' => 0, 'modified' => 0, 'extra' => false]];
    }

    public function index(): string
    {
        $this->gate();
        $files = SystemLogs::files();
        [$name, $f] = $this->file($files);
        $level = (string) Request::query('level', '');
        $q = mb_substr(trim((string) Request::query('q', '')), 0, 200);
        $ref = strtoupper(trim((string) Request::query('ref', '')));

        $all = $f['path'] !== '' ? SystemLogs::entries($f['path']) : [];
        $since = date('Y-m-d H:i:s', time() - 86400);
        $counts = array_fill_keys(array_keys(SystemLogs::LEVELS), 0);
        foreach ($all as $e) {
            if ($e['time'] !== null && $e['time'] >= $since) {
                $counts[$e['level']]++;
            }
        }
        $rows = array_values(array_filter($all, fn ($e) => ($level === '' || $e['level'] === $level)
            && ($q === '' || mb_stripos($e['body'], $q) !== false) && ($ref === '' || str_contains($e['body'], '[ref '.$ref.']'))));
        $page = max(1, (int) Request::query('page', 1));
        $pages = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page = min($page, $pages);

        return view('admin/system-logs', ['title' => __('System logs'), 'files' => $files, 'name' => $name, 'file' => $f, 'level' => $level, 'q' => $q, 'ref' => $ref,
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), 'total' => count($rows), 'all' => count($all),
            'page' => $page, 'pages' => $pages, 'counts' => $counts, 'php' => SystemLogs::phpInfo()]);
    }

    public function download(): never
    {
        $this->gate();
        [$name, $f] = $this->file(SystemLogs::files());
        ($f['path'] !== '' && is_readable($f['path'])) || abort(404);
        session_write_close();
        Activity::log('download', 'system_log', null, $name, ['Downloaded the log :name', ['name' => $name]], [], notify: false);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', basename($f['path'])).'"');
        header('Content-Length: '.filesize($f['path']));
        header('X-Content-Type-Options: nosniff');
        readfile($f['path']);
        exit;
    }

    /** Empties one of the system's own logs (storage/logs); server logs named in config are read-only. */
    public function clear(): never
    {
        $this->gate();
        [$name, $f] = $this->file(SystemLogs::files());
        if ($f['path'] === '' || $f['extra']) {
            back('error', __('This log cannot be cleared from here.'));
        }
        @file_put_contents($f['path'], '');
        Activity::log('deleted', 'system_log', null, $name, ['Cleared the log :name', ['name' => $name]], [], notify: false);
        Session::flash('success', __('The log :name was cleared.', ['name' => $name]));
        redirect('/system-logs?'.http_build_query(['file' => $name]));
    }
}
