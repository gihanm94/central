<?php
declare(strict_types=1);

namespace App\Modules\Sheets\Support;

use App\Core\Support\Google\Google;
use App\Core\Support\Settings;

/** The few Drive + Sheets calls the module needs. */
final class GSheets
{
    private const CHUNK = 2000;                          // rows per write request

    private static function sheetsUrl(): string { return rtrim((string) (Settings::get('google_sheets_url') ?: 'https://sheets.googleapis.com'), '/'); }

    private static function check(array $r, string $what): array
    {
        if ($r['status'] >= 400) {
            throw new \RuntimeException($what.': '.($r['body']['error']['message'] ?? 'HTTP '.$r['status']));
        }

        return $r['body'];
    }

    /** The account's spreadsheets, newest first. @return list<array{id:string,name:string,modified:string}> */
    public static function spreadsheets(int $accountId, string $search = ''): array
    {
        $q = "mimeType = 'application/vnd.google-apps.spreadsheet' and trashed = false";
        if ($search !== '') {
            $q .= " and name contains '".str_replace(['\\', "'"], ['\\\\', "\\'"], $search)."'";
        }
        $body = self::check(Accounts::api($accountId, 'GET', Google::apiUrl().'/drive/v3/files', [
            'q' => $q, 'orderBy' => 'modifiedTime desc', 'pageSize' => 50, 'fields' => 'files(id,name,modifiedTime)',
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ]), __('Google Drive'));

        return array_map(fn ($f) => ['id' => (string) $f['id'], 'name' => (string) $f['name'], 'modified' => (string) ($f['modifiedTime'] ?? '')], $body['files'] ?? []);
    }

    /** @return array{name:string, tabs:list<array{id:int,title:string}>} */
    public static function spreadsheet(int $accountId, string $spreadsheetId): array
    {
        $body = self::check(Accounts::api($accountId, 'GET', self::sheetsUrl().'/v4/spreadsheets/'.rawurlencode($spreadsheetId),
            ['fields' => 'properties.title,sheets.properties(sheetId,title)']), __('Google Sheets'));

        return ['name' => (string) ($body['properties']['title'] ?? ''),
            'tabs' => array_map(fn ($s) => ['id' => (int) $s['properties']['sheetId'], 'title' => (string) $s['properties']['title']], $body['sheets'] ?? [])];
    }

    /** New spreadsheet in the account's Drive. @return array{id:string,name:string} */
    public static function create(int $accountId, string $title, string $tab): array
    {
        $body = self::check(Accounts::api($accountId, 'POST', self::sheetsUrl().'/v4/spreadsheets', [], [
            'properties' => ['title' => $title], 'sheets' => [['properties' => ['title' => $tab]]],
        ]), __('Google Sheets'));

        return ['id' => (string) $body['spreadsheetId'], 'name' => (string) ($body['properties']['title'] ?? $title)];
    }

    /**
     * Replaces the whole tab with $header + $rows (the tab is created when missing and sized to fit,
     * so rows left over from a bigger earlier sync disappear).
     */
    public static function write(int $accountId, string $spreadsheetId, string $tab, array $header, array $rows): void
    {
        $base = self::sheetsUrl().'/v4/spreadsheets/'.rawurlencode($spreadsheetId);
        $tabs = self::spreadsheet($accountId, $spreadsheetId)['tabs'];
        $sheetId = null;
        foreach ($tabs as $t) {
            if ($t['title'] === $tab) { $sheetId = $t['id']; }
        }
        $grid = ['rowCount' => max(2, count($rows) + 1), 'columnCount' => max(1, count($header)), 'frozenRowCount' => 1];
        if ($sheetId === null) {
            self::check(Accounts::api($accountId, 'POST', $base.':batchUpdate', [], ['requests' => [
                ['addSheet' => ['properties' => ['title' => $tab, 'gridProperties' => $grid]]],
            ]]), __('Google Sheets'));
        } else {
            self::check(Accounts::api($accountId, 'POST', $base.':batchUpdate', [], ['requests' => [
                ['updateSheetProperties' => ['properties' => ['sheetId' => $sheetId, 'gridProperties' => $grid], 'fields' => 'gridProperties(rowCount,columnCount,frozenRowCount)']],
            ]]), __('Google Sheets'));
        }
        $range = "'".str_replace("'", "''", $tab)."'";
        self::check(Accounts::api($accountId, 'POST', $base.'/values/'.rawurlencode($range).':clear', [], []), __('Google Sheets'));

        $all = array_merge([$header], $rows);
        foreach (array_chunk($all, self::CHUNK) as $i => $chunk) {
            $at = $range.'!A'.($i * self::CHUNK + 1);
            self::check(Accounts::api($accountId, 'PUT', $base.'/values/'.rawurlencode($at), ['valueInputOption' => 'RAW'],
                ['range' => $at, 'majorDimension' => 'ROWS', 'values' => $chunk]), __('Google Sheets'));
        }
    }
}
