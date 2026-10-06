<?php
declare(strict_types=1);

namespace App\Core\Support;

final class Csv
{
    /** Stream a CSV download and stop. */
    public static function download(string $filename, array $header, iterable $rows): never
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel opens UTF-8 correctly with a BOM
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row));
        }
        fclose($out);
        exit;
    }

    /** Read an uploaded CSV into associative rows keyed by the header. */
    public static function read(array $file, int $maxRows = 5000): array
    {
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => 'Upload failed.']);
        }
        if (! preg_match('/\.(csv|txt)$/i', $file['name'])) {
            throw new ValidationException(['file' => 'Upload a .csv file (in Excel: File > Save As > CSV UTF-8).']);
        }
        $h      = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($h) ?: [];
        $header = array_map(fn ($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), $header);
        $rows   = [];
        while (($line = fgetcsv($h)) !== false && count($rows) < $maxRows) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }
        fclose($h);

        return $rows;
    }
}
