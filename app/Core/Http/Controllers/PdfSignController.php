<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Signatures;
use App\Core\Support\ValidationException;

/**
 * User Management → Sign PDF. A person uploads a PDF, places their own signature (set by Management) on its pages,
 * moves and resizes it, and saves. The page stamps the PDF in the browser; the server keeps the original and the
 * signed copy with fingerprints. People only ever get their own signature and their own signed PDFs.
 */
class PdfSignController extends Controller
{
    public function index(): string
    {
        $this->authorize('pdf_sign', 'view');
        $u = $this->user();
        $f = ['q' => trim((string) Request::query('q', '')), 'from' => (string) Request::query('from', ''), 'to' => (string) Request::query('to', ''), 'page' => (int) Request::query('page', 1)];

        return view('pdf-sign/index', ['title' => __('Sign PDF'), 'f' => $f, 'result' => Signatures::list($f, $u->id), 'signature' => Signatures::of($u->id)]);
    }

    public function create(): string
    {
        $this->authorize('pdf_sign', 'create');

        return view('pdf-sign/editor', ['title' => __('Sign a PDF'), 'signature' => Signatures::of($this->user()->id)]);
    }

    /** The signed-in person's own signature (the only one they can ever load). */
    public function mySignature(): never
    {
        $this->authorize('pdf_sign', 'view');
        $path = Signatures::imagePath($this->user()->id) ?? abort(404, __('You have no signature yet.'));
        self::png($path);
    }

    public static function png(string $path): never
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: image/png');
        header('Content-Length: '.filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function store(): never
    {
        $this->authorize('pdf_sign', 'create');
        $u = $this->user();
        if (! Signatures::imagePath($u->id)) {
            json_response(['message' => __('You have no signature yet. Ask Management to add it.')], 422);
        }
        $raw = json_decode((string) Request::input('placements', '[]'), true);
        $places = [];
        foreach (is_array($raw) ? array_slice($raw, 0, 100) : [] as $p) {
            $n = fn ($k) => is_numeric($p[$k] ?? null) ? max(0.0, min(1.0, round((float) $p[$k], 5))) : 0.0;
            $page = (int) ($p['page'] ?? 0);
            if ($page >= 1 && $n('w') > 0 && $n('h') > 0) {
                $places[] = ['page' => $page, 'x' => $n('x'), 'y' => $n('y'), 'w' => $n('w'), 'h' => $n('h')];
            }
        }
        try {
            $id = Signatures::record($u, Request::file('original') ?? [], Request::file('signed') ?? [], $places, (int) Request::input('pages', 0));
        } catch (ValidationException $e) {
            json_response(['message' => (string) reset($e->errors)], 422);
        }
        Session::flash('success', __('PDF signed and saved.'));
        json_response(['ok' => true, 'url' => url('/sign-pdf/'.$id)]);
    }

    public function show(int $id): string
    {
        $this->authorize('pdf_sign', 'view');
        $row = Signatures::find($this->user(), $id) ?? abort(404, __('This signed PDF is not available.'));

        return view('pdf-sign/show', ['title' => $row['title'], 'row' => $row, 'base' => '/sign-pdf', 'back' => [__('Sign PDF'), '/sign-pdf']]);
    }

    /** Stream the signed or original PDF: the person who signed it, Management or an administrator. */
    public function file(int $id, string $kind): never
    {
        $u = $this->user();
        $row = Signatures::find($u, $id) ?? abort(404);
        $path = Signatures::filePath($row, $kind === 'original' ? 'original' : 'signed') ?? abort(404);
        $name = preg_replace('/\.pdf$/i', '', (string) $row['title']).($kind === 'original' ? '' : ' (signed)').'.pdf';
        if (Request::query('download') === '1') {
            Activity::log('download', 'pdf_signature', $id, $row['title'], ['Downloaded the :kind PDF ":label"', ['kind' => $kind === 'original' ? 'original' : 'signed', 'label' => $row['title']]], [], null, notify: false);
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: '.(Request::query('download') === '1' ? 'attachment' : 'inline').'; filename="'.preg_replace('/[^A-Za-z0-9._ ()-]/', '_', $name).'"; filename*=UTF-8\'\''.rawurlencode($name));
        header('Content-Length: '.filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }
}
