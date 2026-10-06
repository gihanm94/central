<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\Csv;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Settings;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Data\Exporter;
use App\Modules\CRM\Support\Data\Importer;
use App\Modules\CRM\Support\Data\Schema;
use App\Modules\CRM\Support\Data\Source;
use App\Modules\CRM\Support\Data\Xlsx;

/**
 * Admin only: move CRM data in and out in bulk.
 *   Export  Excel (.xlsx), CSV or SQL INSERT statements, any tables, optional department / date filter.
 *   Import  Excel, CSV or an SQL file → pick the table → map every file column to a table column → check → import.
 * SQL files are only read for their data (INSERT / COPY); nothing in them is ever executed.
 */
class DataController extends Controller
{
    private function gate(): void
    {
        if (! $this->user()->isAdmin()) {
            abort(403, __('Only an administrator can use the data tools.'));
        }
    }

    public function index(): string
    {
        $this->gate();
        Source::sweep();
        $tables = [];
        foreach (Schema::TABLES as $t => $_) {
            $tables[$t] = ['label' => Schema::label($t), 'count' => (int) DB::scalar("SELECT count(*) FROM {$t}".(isset(Schema::columns($t)['id']) ? '' : ''), [], 'crm')];
        }

        return view('crm/data/index', ['title' => __('Data tools'), 'tables' => $tables, 'departments' => Access::departments(), 'excel' => Xlsx::available(), 'tab' => Request::query('tab') === 'export' ? 'export' : 'import']);
    }

    /* ----------------------------------------------------------------- export */

    public function export(): never
    {
        $this->gate();
        $picked = array_values(array_filter((array) Request::input('tables', []), fn ($t) => Schema::allowed((string) $t)));
        $format = (string) Request::input('format', 'xlsx');
        if (! $picked) { throw new ValidationException(['tables' => __('Tick at least one table.')]); }
        if (! in_array($format, ['xlsx', 'csv', 'sql'], true)) { $format = 'xlsx'; }
        if ($format === 'xlsx' && ! Xlsx::available()) { throw new ValidationException(['format' => __('Excel needs the PHP zip extension on this server. Use CSV or SQL.')]); }
        $opt = ['names' => (bool) Request::input('names'), 'department' => (int) Request::input('department'), 'from' => $this->day(Request::input('from')), 'to' => $this->day(Request::input('to'))];

        $data = [];
        foreach ($picked as $t) { $data[$t] = Exporter::table($t, $opt); }
        $stamp = date('Ymd-His');
        Activity::log('export', 'crm_data', null, implode(', ', $picked), ['Exported CRM data (:tables) as :format', ['tables' => implode(', ', $picked), 'format' => strtoupper($format)]], [], null, null, true, 'crm');

        if ($format === 'sql') {
            $out = "-- ".Settings::get('company_name', config('app.name'))." CRM data export, ".date('Y-m-d H:i')."\n-- Only INSERT statements: create the tables first (the app does this on install).\n\n";
            foreach ($data as $t => [$header, $rows, $cols]) {
                $out .= "-- {$t} (".count($rows)." rows)\n".Exporter::sql($t, $header, $rows, $cols);
                if (isset($cols['id'])) { $out .= "SELECT setval(pg_get_serial_sequence('{$t}', 'id'), GREATEST((SELECT COALESCE(MAX(id), 1) FROM {$t}), 1));\n"; }
                $out .= "\n";
            }
            $this->send('crm-export-'.$stamp.'.sql', 'application/sql', $out);
        }
        if ($format === 'csv' && count($data) === 1) {
            [$t, [$header, $rows]] = [array_key_first($data), reset($data)];
            Csv::download('crm-'.$t.'-'.$stamp.'.csv', $header, $rows);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'crm');
        if ($format === 'xlsx') {
            $sheets = [];
            foreach ($data as $t => [$header, $rows, $cols]) { $sheets[$t] = [$header, Exporter::forExcel($rows, array_values($cols))]; }
            Xlsx::build($sheets, $tmp);
            $this->sendFile($tmp, 'crm-export-'.$stamp.'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        // several CSV files in one zip
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($data as $t => [$header, $rows]) {
            $h = fopen('php://temp', 'r+');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, $header);
            foreach ($rows as $r) { fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $r)); }
            rewind($h);
            $zip->addFromString($t.'.csv', (string) stream_get_contents($h));
            fclose($h);
        }
        $zip->close();
        $this->sendFile($tmp, 'crm-export-'.$stamp.'.zip', 'application/zip');
    }

    private function day(mixed $v): ?string
    {
        $v = trim((string) $v);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    private function send(string $name, string $type, string $body): never
    {
        header('Content-Type: '.$type.'; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }

    private function sendFile(string $path, string $name, string $type): never
    {
        header('Content-Type: '.$type);
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Cache-Control: no-store');
        readfile($path);
        @unlink($path);
        exit;
    }

    /* ----------------------------------------------------------------- import */

    public function upload(): never
    {
        $this->gate();
        $f = Request::file('file');
        if (! $f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => __('Choose a file to upload.')]);
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (! in_array($ext, ['xlsx', 'csv', 'txt', 'sql'], true)) {
            throw new ValidationException(['file' => __('Upload an Excel (.xlsx), CSV or SQL file. (Old .xls files: save them as .xlsx first.)')]);
        }
        if ($ext === 'xlsx' && ! Xlsx::available()) {
            throw new ValidationException(['file' => __('Excel needs the PHP zip extension on this server. Save the sheet as CSV instead.')]);
        }
        if (($f['size'] ?? 0) > Source::MAX_BYTES) {
            throw new ValidationException(['file' => __('The file is bigger than 25 MB. Split it into smaller files.')]);
        }
        $token = bin2hex(random_bytes(16));
        $ext   = $ext === 'txt' ? 'csv' : $ext;
        if (! move_uploaded_file($f['tmp_name'], Source::dir().'/'.$token.'.'.$ext)) {
            throw new ValidationException(['file' => __('The file could not be saved.')]);
        }
        file_put_contents(Source::dir().'/'.$token.'.name', (string) $f['name']);
        try {
            Source::load(Source::dir().'/'.$token.'.'.$ext);
        } catch (\Throwable $e) {
            Source::forget($token);
            @unlink(Source::dir().'/'.$token.'.name');
            throw new ValidationException(['file' => $e->getMessage() ?: __('This file could not be read.')]);
        }
        redirect('/crm/data/map?token='.$token);
    }

    private function file(string $token): array
    {
        $path = Source::path($token) ?? abort(404, __('This upload has expired. Upload the file again.'));
        $name = is_file(Source::dir().'/'.$token.'.name') ? (string) file_get_contents(Source::dir().'/'.$token.'.name') : basename($path);

        return [$path, $name];
    }

    public function map(): string
    {
        $this->gate();
        $token = (string) Request::query('token');
        [$path, $fileName] = $this->file($token);
        $withHeader = Request::query('header', '1') !== '0';
        try {
            $sheets = Source::load($path, $withHeader);
        } catch (\Throwable $e) {
            abort(422, $e->getMessage());
        }
        $names = array_keys($sheets);
        $sheet = (string) Request::query('sheet', '');
        if (! isset($sheets[$sheet])) { $sheet = (string) array_key_first(array_filter($sheets, fn ($s) => $s['rows']) ?: $sheets); }
        $table = (string) Request::query('table', '');
        if (! Schema::allowed($table)) {
            $table = '';
            $norm = fn ($s) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $s));
            foreach (Schema::TABLES as $t => $info) {
                if ($norm($t) === $norm($sheet) || $norm($info[0]) === $norm($sheet)) { $table = $t; break; }
            }
        }
        $data    = $sheets[$sheet] ?? ['header' => [], 'rows' => []];
        $columns = $table ? Schema::columns($table) : [];
        $saved   = $table ? DB::select('SELECT id, name FROM import_mappings WHERE target = ? ORDER BY name', [$table], 'crm') : [];
        $suggest = $table ? Schema::suggest($table, $data['header']) : [];
        $fixed   = [];
        if ($table && ($sid = (int) Request::query('saved'))) {
            $m = DB::first('SELECT mapping FROM import_mappings WHERE id = ? AND target = ?', [$sid, $table], 'crm');
            foreach (json_decode((string) ($m['mapping'] ?? '[]'), true) ?: [] as $col => $x) {
                if (! isset($columns[$col])) { continue; }
                $idx = ($x['header'] ?? null) !== null ? array_search($x['header'], $data['header'], true) : false;
                $suggest[$col] = $idx === false ? null : $idx;
                if (! empty($x['fixed'])) { $fixed[$col] = (string) $x['fixed']; }
            }
        }

        return view('crm/data/map', [
            'title' => __('Import data'), 'token' => $token, 'fileName' => $fileName, 'sheets' => array_map(fn ($s) => count($s['rows']), $sheets), 'sheet' => $sheet, 'sheetNames' => $names,
            'table' => $table, 'tables' => array_map(fn ($t) => Schema::label($t), array_combine(array_keys(Schema::TABLES), array_keys(Schema::TABLES))),
            'columns' => $columns, 'header' => $data['header'], 'sample' => array_slice($data['rows'], 0, 5), 'rowCount' => count($data['rows']),
            'suggest' => $suggest, 'fixed' => $fixed, 'withHeader' => $withHeader, 'saved' => $saved, 'savedId' => (int) Request::query('saved'),
            'isSql' => str_ends_with($path, '.sql'),
        ]);
    }

    public function run(): string
    {
        $this->gate();
        $token = (string) Request::input('token');
        [$path, $fileName] = $this->file($token);
        $table = (string) Request::input('table');
        if (! Schema::allowed($table)) { abort(404); }
        $withHeader = Request::input('header', '1') !== '0';
        $sheets = Source::load($path, $withHeader);
        $sheet  = (string) Request::input('sheet');
        $data   = $sheets[$sheet] ?? abort(404);
        $mode   = in_array(Request::input('mode'), ['insert', 'update_code', 'update_id'], true) ? (string) Request::input('mode') : 'insert';
        $cols   = Schema::columns($table);

        $srcs = (array) Request::input('map', []);
        $fix  = (array) Request::input('fixed', []);
        $map  = [];
        $save = [];
        foreach ($cols as $name => $_) {
            $src = $srcs[$name] ?? '';
            $src = ($src !== '' && ctype_digit((string) $src) && (int) $src < count($data['header'])) ? (int) $src : null;
            $map[$name]  = ['src' => $src, 'fixed' => trim((string) ($fix[$name] ?? ''))];
            if ($src !== null || $map[$name]['fixed'] !== '') { $save[$name] = ['header' => $src !== null ? $data['header'][$src] : null, 'fixed' => $map[$name]['fixed']]; }
        }
        if (! array_filter($map, fn ($m) => $m['src'] !== null || $m['fixed'] !== '')) {
            throw new ValidationException(['map' => __('Map at least one column.')]);
        }
        if ($mode !== 'insert' && (($map[$mode === 'update_id' ? 'id' : 'code']['src'] ?? null) === null)) {
            throw new ValidationException(['mode' => __('To update existing rows, map the :key column.', ['key' => $mode === 'update_id' ? 'id' : 'code'])]);
        }
        $name = trim((string) Request::input('save_as'));
        if ($name !== '') {
            DB::insert('import_mappings', ['name' => mb_substr($name, 0, 120), 'target' => $table, 'mapping' => json_encode($save, JSON_UNESCAPED_UNICODE), 'created_by' => $this->user()->id], 'crm');
        }

        $dry = Request::input('action') !== 'import';
        @set_time_limit(300);
        $importer = new Importer($this->user()->id, $this->user()->department_id);
        $result   = $importer->run($table, $data['rows'], $map, $mode, $dry);
        if (! $dry) {
            Activity::log('import', 'crm_data', null, $table, ['Imported :n rows into :table from ":file"', ['n' => $result['inserted'] + $result['updated'], 'table' => $table, 'file' => $fileName]],
                ['changes' => ['inserted' => ['from' => '', 'to' => (string) $result['inserted']], 'updated' => ['from' => '', 'to' => (string) $result['updated']], 'skipped' => ['from' => '', 'to' => (string) $result['skipped']]]], null, null, true, 'crm');
        }

        return view('crm/data/result', [
            'title' => $dry ? __('Check result') : __('Import result'), 'dry' => $dry, 'result' => $result, 'table' => $table, 'tableLabel' => Schema::label($table), 'token' => $token, 'sheet' => $sheet, 'fileName' => $fileName,
            'post' => ['token' => $token, 'sheet' => $sheet, 'table' => $table, 'header' => $withHeader ? '1' : '0', 'mode' => $mode, 'map' => array_map(fn ($m) => $m['src'] ?? '', $map), 'fixed' => array_map(fn ($m) => $m['fixed'], $map)],
        ]);
    }

    public function discard(): never
    {
        $this->gate();
        $token = (string) Request::input('token');
        if (preg_match('/^[a-f0-9]{32}$/', $token)) {
            Source::forget($token);
            @unlink(Source::dir().'/'.$token.'.name');
        }
        Session::flash('success', __('The uploaded file was removed.'));
        redirect('/crm/data');
    }

    public function deleteMapping(int $id): never
    {
        $this->gate();
        DB::exec('DELETE FROM import_mappings WHERE id = ?', [$id], 'crm');
        Session::flash('success', __('Saved mapping deleted.'));
        back();
    }
}
