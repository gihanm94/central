<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support\Data;

/**
 * Minimal Excel (.xlsx) reader and writer — no library needed, only PHP's zip + xml extensions.
 * Reads every sheet into rows of strings (dates become 2026-10-06 / 2026-10-06 14:30).
 * Writes one or more sheets with a bold header row.
 */
final class Xlsx
{
    public static function available(): bool { return class_exists(\ZipArchive::class) && class_exists(\XMLReader::class); }

    /* ------------------------------------------------------------------ read */

    /** @return array<string, array<int, array<int, string>>> sheet name => rows */
    public static function read(string $path, int $maxRows = 50000): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('This is not a valid Excel file.');
        }
        try {
            $shared = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $doc = simplexml_load_string($xml);
                foreach ($doc->si ?? [] as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string) $si->t;
                    } else {
                        foreach ($si->r ?? [] as $r) { $text .= (string) $r->t; }
                    }
                    $shared[] = $text;
                }
            }
            $dateStyles = self::dateStyles((string) $zip->getFromName('xl/styles.xml'));
            $wb   = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml')) ?: throw new \RuntimeException('Cannot read the workbook.');
            $rels = [];
            $relDoc = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
            foreach ($relDoc->Relationship ?? [] as $rel) {
                $rels[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
            }
            $out = [];
            foreach ($wb->sheets->sheet ?? [] as $sheet) {
                $rid    = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target = $rels[$rid] ?? null;
                if (! $target) { continue; }
                $file = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                $data = $zip->getFromName($file);
                if ($data === false) { continue; }
                $out[(string) $sheet['name']] = self::sheetRows($data, $shared, $dateStyles, $maxRows);
            }

            return $out;
        } finally {
            $zip->close();
        }
    }

    private static function sheetRows(string $xml, array $shared, array $dateStyles, int $max): array
    {
        $r = new \XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $rows = [];
        while ($r->read()) {
            if ($r->nodeType !== \XMLReader::ELEMENT || $r->name !== 'row') { continue; }
            $row = [];
            $node = simplexml_load_string($r->readOuterXml());
            foreach ($node->c ?? [] as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex($ref);
                $t   = (string) $c['t'];
                $v   = isset($c->v) ? (string) $c->v : '';
                if ($t === 's')              { $v = $shared[(int) $v] ?? ''; }
                elseif ($t === 'inlineStr')  { $v = isset($c->is->t) ? (string) $c->is->t : implode('', array_map(fn ($x) => (string) $x->t, iterator_to_array($c->is->r ?? [], false))); }
                elseif ($t === 'b')          { $v = $v === '1' ? 'TRUE' : 'FALSE'; }
                elseif ($t === '' || $t === 'n') {
                    if ($v !== '' && isset($dateStyles[(int) $c['s']]) && is_numeric($v)) { $v = self::excelDate((float) $v); }
                    elseif ($v !== '' && is_numeric($v) && str_contains($v, 'E')) { $v = rtrim(rtrim(sprintf('%.10F', (float) $v), '0'), '.'); }
                }
                $row[$col] = $v;
            }
            if ($row) {
                $n = max(array_keys($row)) + 1;
                $full = array_fill(0, $n, '');
                foreach ($row as $i => $v) { $full[$i] = $v; }
                $rows[] = $full;
            } else {
                $rows[] = [];
            }
            if (count($rows) > $max) { throw new \RuntimeException("The sheet has more than {$max} rows. Split it into smaller files."); }
        }
        $r->close();
        while ($rows && ! array_filter(end($rows), fn ($v) => $v !== '')) { array_pop($rows); }

        return $rows;
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', $ref, $m);
        $n = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) { $n = $n * 26 + (ord($ch) - 64); }

        return $n - 1;
    }

    /** Style ids (cellXfs positions) that show a date or time. */
    private static function dateStyles(string $xml): array
    {
        if ($xml === '') { return []; }
        $doc = simplexml_load_string($xml);
        $custom = [];
        foreach ($doc->numFmts->numFmt ?? [] as $f) {
            $code = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string) $f['formatCode']));
            $custom[(int) $f['numFmtId']] = (bool) preg_match('/[dmyhs]/', $code) && ! preg_match('/^[#0.,%\s]+$/', $code);
        }
        $builtIn = array_merge(range(14, 22), range(45, 47));
        $out = [];
        $i = 0;
        foreach ($doc->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (in_array($id, $builtIn, true) || ! empty($custom[$id])) { $out[$i] = true; }
            $i++;
        }

        return $out;
    }

    private static function excelDate(float $serial): string
    {
        $secs = (int) round(($serial - 25569) * 86400);
        $d    = gmdate('Y-m-d', $secs);

        return fmod($serial, 1.0) > 0.0001 ? gmdate('Y-m-d H:i', $secs) : $d;
    }

    /* ----------------------------------------------------------------- write */

    /** @param array<string, array{0: string[], 1: iterable}> $sheets sheet name => [header, rows] */
    public static function build(array $sheets, string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create the Excel file.');
        }
        $names = array_keys($sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($names as $i => $_) { $ct .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; }
        $zip->addFromString('[Content_Types].xml', $ct.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($names as $i => $n) {
            $wb  .= '<sheet name="'.self::esc(self::sheetName($n, $i)).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
            $rel .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $rel .= '<Relationship Id="rId'.(count($names) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rel);
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
        $i = 0;
        foreach ($sheets as [$header, $rows]) {
            $i++;
            $tmp = tempnam(sys_get_temp_dir(), 'xl');
            $fh  = fopen($tmp, 'wb');
            fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>');
            fwrite($fh, self::rowXml(1, $header, 1));
            $n = 1;
            foreach ($rows as $row) { fwrite($fh, self::rowXml(++$n, array_values($row), 0)); }
            fwrite($fh, '</sheetData></worksheet>');
            fclose($fh);
            $zip->addFile($tmp, 'xl/worksheets/sheet'.$i.'.xml');
            $cleanup[] = $tmp;
        }
        $zip->close();
        foreach ($cleanup ?? [] as $f) { @unlink($f); }
    }

    private static function sheetName(string $n, int $i): string
    {
        $n = trim(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $n)) ?: 'Sheet'.($i + 1);

        return mb_substr($n, 0, 31);
    }

    private static function rowXml(int $r, array $cells, int $style): string
    {
        $x = '<row r="'.$r.'">';
        foreach ($cells as $i => $v) {
            if ($v === null || $v === '') { continue; }
            $ref = self::colName($i).$r;
            if (is_int($v) || is_float($v)) {
                $x .= '<c r="'.$ref.'"'.($style ? ' s="'.$style.'"' : '').'><v>'.$v.'</v></c>';
            } else {
                $x .= '<c r="'.$ref.'" t="inlineStr"'.($style ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.self::esc((string) $v).'</t></is></c>';
            }
        }

        return $x.'</row>';
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) { $s = chr(65 + ($n - 1) % 26).$s; }

        return $s;
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
