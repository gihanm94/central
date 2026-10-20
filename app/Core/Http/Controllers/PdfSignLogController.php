<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Signatures;

/**
 * The PDF signing log: who signed which PDF and when, with a preview of each signed copy.
 * Shown in Administrator → PDF signing log (/pdf-sign-log) and Management → PDF signing log (/management/pdf-signs).
 */
class PdfSignLogController extends Controller
{
    private function base(): string
    {
        return str_starts_with(Request::path(), '/management/') ? '/management/pdf-signs' : '/pdf-sign-log';
    }

    private function gate(): void
    {
        if (! Signatures::canAudit($this->user())) {
            abort(403);
        }
    }

    public function index(): string
    {
        $this->gate();
        $f = ['q' => trim((string) Request::query('q', '')), 'user' => (int) Request::query('user', 0), 'from' => (string) Request::query('from', ''),
            'to' => (string) Request::query('to', ''), 'page' => (int) Request::query('page', 1)];
        $people = array_column(DB::select('SELECT DISTINCT u.id, u.name FROM pdf_signatures s JOIN users u ON u.id = s.user_id ORDER BY u.name'), 'name', 'id');

        return view('pdf-sign/log', ['title' => __('PDF signing log'), 'f' => $f, 'result' => Signatures::list($f), 'people' => $people, 'base' => $this->base()]);
    }

    public function show(int $id): string
    {
        $this->gate();
        $row = Signatures::find($this->user(), $id) ?? abort(404);

        return view('pdf-sign/show', ['title' => $row['title'], 'row' => $row, 'base' => '/sign-pdf', 'back' => [__('PDF signing log'), $this->base()], 'audit' => true]);
    }
}
