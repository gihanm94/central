<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\Files;
use App\Core\Support\Request;
use App\Core\Support\Session;

/** Administrators: look at the files the system keeps, search them, move / rename / delete / upload / download. */
class FileController extends Controller
{
    private function gate(): void
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can open this.'));
    }

    private function root(): string
    {
        $r = (string) Request::input('root', Request::query('root', 'uploads'));

        return isset(Files::roots()[$r]) ? $r : 'uploads';
    }

    private function back(string $root, string $path): never
    {
        redirect('/files?'.http_build_query(array_filter(['root' => $root, 'path' => $path])));
    }

    private function fail(string $root, string $path, \Throwable $e): never
    {
        Session::flash('error', $e->getMessage());
        $this->back($root, $path);
    }

    public function index(): string
    {
        $this->gate();
        $root = $this->root();
        $path = '';
        try { $path = Files::clean((string) Request::query('path', '')); Files::abs($root, $path); }
        catch (\Throwable) { $path = ''; }
        $per = in_array((int) Request::query('per_page', 20), [10, 20, 50, 100], true) ? (int) Request::query('per_page', 20) : 20;
        $page = max(1, (int) Request::query('page', 1));
        $res = Files::list($root, $path, trim((string) Request::query('q', '')), (string) Request::query('sort', 'name'), strtolower((string) Request::query('dir', 'asc')), $page, $per);

        return view('admin/files', ['title' => __('File manager'), 'roots' => Files::roots(), 'root' => $root, 'path' => $path, 'rows' => $res['rows'], 'total' => $res['total'], 'perPage' => $per, 'page' => $page,
            'q' => trim((string) Request::query('q', '')), 'folders' => Files::folders($root), 'sort' => (string) Request::query('sort', 'name'), 'dir' => strtolower((string) Request::query('dir', 'asc')) === 'desc' ? 'desc' : 'asc']);
    }

    public function download(): never
    {
        $this->gate();
        try { $abs = Files::abs($this->root(), (string) Request::query('path', '')); } catch (\Throwable $e) { abort(404); }
        is_file($abs) || abort(404);
        session_write_close();
        Activity::log('download', 'file', null, basename($abs), ['Downloaded the file ":name"', ['name' => basename($abs)]], [], null, null, false);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.addcslashes(preg_replace('/[^\x20-\x7e]/', '_', basename($abs)), '"\\').'"; filename*=UTF-8\'\''.rawurlencode(basename($abs)));
        header('Content-Length: '.filesize($abs));
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
        exit;
    }

    public function upload(): never
    {
        $this->gate();
        $root = $this->root();
        $path = Files::clean((string) Request::input('path', ''));
        try {
            $dir = Files::abs($root, $path);
            $n = 0;
            $f = $_FILES['files'] ?? null;
            foreach (is_array($f['name'] ?? null) ? array_keys($f['name']) : [] as $i) {
                if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK) { continue; }
                $name = Files::name((string) $f['name'][$i]);
                $target = $dir.'/'.$name;
                if (file_exists($target)) { $name = pathinfo($name, PATHINFO_FILENAME).'-'.date('His').'.'.pathinfo($name, PATHINFO_EXTENSION); $target = $dir.'/'.$name; }
                move_uploaded_file($f['tmp_name'][$i], $target) && $n++;
            }
            Activity::log('created', 'file', null, $path ?: $root, ['Uploaded :n file(s) to ":p"', ['n' => $n, 'p' => $root.'/'.$path]], [], null, null, false);
            Session::flash($n ? 'success' : 'error', $n ? __(':n file(s) uploaded.', ['n' => $n]) : __('Nothing was uploaded.'));
        } catch (\Throwable $e) { $this->fail($root, $path, $e); }
        $this->back($root, $path);
    }

    public function mkdir(): never
    {
        $this->gate();
        $root = $this->root();
        $path = Files::clean((string) Request::input('path', ''));
        try {
            $name = Files::name((string) Request::input('name', ''));
            $abs = Files::abs($root, ($path === '' ? '' : $path.'/').$name, true);
            file_exists($abs) ? throw new \RuntimeException(__('That name is already used.')) : mkdir($abs, 0775);
            Session::flash('success', __('Folder created.'));
        } catch (\Throwable $e) { $this->fail($root, $path, $e); }
        $this->back($root, $path);
    }

    public function rename(): never
    {
        $this->gate();
        $root = $this->root();
        $path = Files::clean((string) Request::input('path', ''));
        try {
            $from = Files::abs($root, (string) Request::input('item', ''));
            $name = Files::name((string) Request::input('name', ''));
            $to = dirname($from).'/'.$name;
            file_exists($to) ? throw new \RuntimeException(__('That name is already used.')) : rename($from, $to);
            Activity::log('updated', 'file', null, $name, ['Renamed ":a" to ":b"', ['a' => basename($from), 'b' => $name]], [], null, null, false);
            Session::flash('success', __('Renamed.'));
        } catch (\Throwable $e) { $this->fail($root, $path, $e); }
        $this->back($root, $path);
    }

    /** Move one or more items into a folder of the same root. */
    public function move(): never
    {
        $this->gate();
        $root = $this->root();
        $path = Files::clean((string) Request::input('path', ''));
        try {
            $destRel = Files::clean((string) Request::input('dest', ''));
            $dest = Files::abs($root, $destRel);
            is_dir($dest) || throw new \RuntimeException(__('Choose a folder.'));
            $n = 0;
            foreach ((array) Request::input('items', []) as $item) {
                $from = Files::abs($root, (string) $item);
                $to = $dest.'/'.basename($from);
                if (is_dir($from) && ($dest === $from || str_starts_with($dest.'/', $from.'/'))) { throw new \RuntimeException(__('A folder cannot go inside itself.')); }
                if (file_exists($to)) { throw new \RuntimeException(__('“:n” already exists there.', ['n' => basename($from)])); }
                rename($from, $to) && $n++;
            }
            Activity::log('updated', 'file', null, $destRel ?: $root, ['Moved :n item(s) to ":p"', ['n' => $n, 'p' => $root.'/'.$destRel]], [], null, null, false);
            Session::flash('success', __(':n item(s) moved.', ['n' => $n]));
        } catch (\Throwable $e) { $this->fail($root, $path, $e); }
        $this->back($root, $path);
    }

    /** Delete one (path in the query) or several (ids[]) — the word DELETE must be typed. */
    public function delete(): never
    {
        $this->gate();
        $root = $this->root();
        $path = Files::clean((string) Request::input('path', ''));
        if (Request::input('confirm') !== 'DELETE') { Session::flash('error', __('Type DELETE to confirm.')); $this->back($root, $path); }
        $items = (array) Request::input('ids', []);
        if (($one = (string) Request::query('item', '')) !== '') { $items[] = $one; }
        $n = 0;
        try {
            foreach ($items as $item) {
                $abs = Files::abs($root, (string) $item);
                $abs === Files::root($root) ? throw new \RuntimeException(__('The top folder cannot be deleted.')) : Files::delete($abs);
                $n++;
            }
            Activity::log('deleted', 'file', null, $root.'/'.$path, ['Deleted :n file(s) or folder(s) in ":p"', ['n' => $n, 'p' => $root.'/'.$path]], [], null, null, false);
            Session::flash('success', __(':n deleted.', ['n' => $n]));
        } catch (\Throwable $e) { Session::flash('error', $e->getMessage()); }
        $this->back($root, $path);
    }
}
