<?php
declare(strict_types=1);

namespace App\Modules\Machines\Controllers;

use App\Core\Support\DB;
use App\Modules\Machines\Support\Files;
use App\Core\Http\Controllers\Controller;

/** Opens a stored document for a signed-in person who may see any machine screen. */
class FileController extends Controller
{
    public function show(): never
    {
        $this->user();
        $f = (string) \App\Core\Support\Request::query('f', '');
        if (! (can('machines_machines', 'view') || can('machines_register', 'view') || can('machines_maintenance', 'view') || can('machines_calibration', 'view') || can('machines_checklists', 'view'))) {
            abort(403);
        }
        $path = Files::path($f) ?? abort(404, __('File not found.'));
        $name = null;
        foreach (['register_request' => ['attachment', 'work_instruction', 'warranty_files'], 'machine' => ['work_instruction', 'warranty_files'], 'maintenance_record' => ['attachment'], 'calibration_record' => ['attachment']] as $table => $cols) {
            foreach ($cols as $col) {
                $json = DB::scalar("SELECT {$col} FROM {$table} WHERE {$col} LIKE ? LIMIT 1", ['%"f":"'.$f.'"%'], 'machines');
                if ($json && ($name = Files::label($f, [(string) $json]))) {
                    break 2;
                }
            }
        }
        $name ??= $f;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $inline = in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'text/plain'], true);
        header('Content-Type: '.$mime);
        header('Content-Length: '.filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.rawurlencode($name).'"; filename*=UTF-8\'\''.rawurlencode($name));
        readfile($path);
        exit;
    }
}
