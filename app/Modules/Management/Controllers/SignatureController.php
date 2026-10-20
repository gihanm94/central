<?php
declare(strict_types=1);

namespace App\Modules\Management\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\Controllers\PdfSignController;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Signatures;
use App\Core\Support\ValidationException;

/**
 * Management → Member signatures: every active member with their signature. Management uploads a picture of it or
 * draws it on screen; the person can then place it on PDFs in User Management → Sign PDF (only their own).
 */
class SignatureController extends Controller
{
    private function member(int $id): array
    {
        return DB::first('SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL', [$id]) ?? abort(404);
    }

    public function index(): string
    {
        $this->authorize('management', 'view');
        $q   = trim((string) Request::query('q', ''));
        $dep = (int) Request::query('department', 0);
        $has = (string) Request::query('has', '');
        $w = ['u.deleted_at IS NULL', 'u.is_active'];
        $p = [];
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)).'%';
            $w[] = '(u.name ILIKE ? OR u.email ILIKE ? OR u.username ILIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if ($dep) { $w[] = 'u.department_id = ?'; $p[] = $dep; }
        if ($has === 'yes') { $w[] = 's.user_id IS NOT NULL'; }
        if ($has === 'no') { $w[] = 's.user_id IS NULL'; }
        $rows = DB::select('SELECT u.id, u.name, u.avatar, u.email, d.name AS department, ep.job_title, s.source, s.updated_at AS signed_at, ub.name AS signed_by,
                (SELECT count(*) FROM pdf_signatures ps WHERE ps.user_id = u.id) AS pdfs
            FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN employee_profiles ep ON ep.user_id = u.id
            LEFT JOIN user_signatures s ON s.user_id = u.id LEFT JOIN users ub ON ub.id = s.updated_by
            WHERE '.implode(' AND ', $w).' ORDER BY (s.user_id IS NULL), u.name LIMIT 500', $p);
        $counts = DB::first('SELECT count(*) AS members, count(s.user_id) AS signed FROM users u LEFT JOIN user_signatures s ON s.user_id = u.id WHERE u.deleted_at IS NULL AND u.is_active');

        return view('management::signatures', ['title' => __('Member signatures'), 'rows' => $rows, 'q' => $q, 'dep' => $dep, 'has' => $has, 'counts' => $counts,
            'deps' => array_column(DB::select('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name'), 'name', 'id')]);
    }

    public function image(int $id): never
    {
        $this->authorize('management', 'view');
        PdfSignController::png(Signatures::imagePath($id) ?? abort(404));
    }

    /** Save from an uploaded image ("file") or the drawing pad ("drawn" = PNG data URL). */
    public function save(int $id): never
    {
        $this->authorize('management', 'view');
        $m = $this->member($id);
        $drawn = (string) Request::input('drawn', '');
        if ($drawn !== '') {
            if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $drawn, $mm)) {
                throw new ValidationException(['signature' => __('The drawing could not be read. Try again.')]);
            }
            Signatures::save($id, (string) base64_decode($mm[1], true), 'drawn', $this->user()->id);
            $how = 'drawn';
        } else {
            $f = Request::file('file');
            if (! $f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || ! is_uploaded_file($f['tmp_name'])) {
                throw new ValidationException(['signature' => __('Choose an image of the signature, or draw it.')]);
            }
            Signatures::save($id, (string) file_get_contents($f['tmp_name']), 'upload', $this->user()->id);
            $how = 'upload';
        }
        Activity::log('updated', 'signature', $id, $m['name'], [$how === 'drawn' ? 'Drew the signature of :name' : 'Uploaded the signature of :name', ['name' => $m['name']]], [], $id, notify: false);
        Session::flash('success', __('Signature of :name saved.', ['name' => $m['name']]));
        redirect('/management/signatures'.(Request::input('return') ? '?'.http_build_query(['q' => Request::input('q')]) : ''));
    }

    public function remove(int $id): never
    {
        $this->authorize('management', 'view');
        $m = $this->member($id);
        if (Signatures::remove($id)) {
            Activity::log('deleted', 'signature', $id, $m['name'], ['Removed the signature of :name', ['name' => $m['name']]], [], $id, notify: false);
            Session::flash('success', __('Signature of :name removed.', ['name' => $m['name']]));
        }
        redirect('/management/signatures');
    }
}
