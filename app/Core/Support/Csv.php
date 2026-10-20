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
        if (preg_match('/\.xlsx$/i', $file['name'])) {
            return self::xlsx($file['tmp_name'], $maxRows);
        }
        if (! preg_match('/\.(csv|txt)$/i', $file['name'])) {
            throw new ValidationException(['file' => 'Upload a .csv or .xlsx file.']);
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

    /** The first sheet of an Excel file, read the same way as a CSV (first row = column names). */
    private static function xlsx(string $path, int $maxRows): array
    {
        try {
            $sheet = Xlsx::read($path, $maxRows + 1);
        } catch (\RuntimeException $e) {
            throw new ValidationException(['file' => $e->getMessage()]);
        }
        $lines  = (array) (reset($sheet) ?: []);
        $header = array_map(fn ($c) => strtolower(trim((string) $c)), array_values((array) array_shift($lines)));
        $rows   = [];
        foreach ($lines as $line) {
            $line = array_values(array_map(fn ($v) => (string) $v, (array) $line));
            if (count(array_filter($line, fn ($v) => trim($v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }

        return $rows;
    }
}
