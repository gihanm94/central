<?php
declare(strict_types=1);

namespace App\Core\Support\Google;

/** The signed-in person's Gmail: list, read, send. */
final class Gmail
{
    private const BASE = '/gmail/v1/users/me';

    private static function ok(array $r): array
    {
        if ($r['status'] >= 400) { throw new \RuntimeException(($r['body']['error']['message'] ?? 'Gmail error').' ('.$r['status'].')'); }

        return $r['body'];
    }

    /** @return array{rows: array, next: ?string, estimate: int} */
    public static function inbox(int $userId, string $label, string $q, ?string $page, int $per = 15): array
    {
        $list = self::ok(Google::api($userId, 'GET', self::BASE.'/messages', array_filter(['labelIds' => $label, 'q' => $q, 'maxResults' => $per, 'pageToken' => $page])));
        $rows = [];
        foreach ($list['messages'] ?? [] as $m) {
            $x = self::ok(Google::api($userId, 'GET', self::BASE.'/messages/'.rawurlencode((string) $m['id']), ['format' => 'metadata', 'metadataHeaders' => ['From', 'Subject', 'Date']]));
            $h = self::headers($x['payload']['headers'] ?? []);
            $rows[] = ['id' => (string) $x['id'], 'thread' => (string) ($x['threadId'] ?? ''), 'from' => $h['from'] ?? '', 'subject' => $h['subject'] ?? '', 'date' => self::date($h['date'] ?? '', $x['internalDate'] ?? null),
                'snippet' => html_entity_decode((string) ($x['snippet'] ?? ''), ENT_QUOTES, 'UTF-8'), 'unread' => in_array('UNREAD', $x['labelIds'] ?? [], true), 'attachments' => false];
        }

        return ['rows' => $rows, 'next' => $list['nextPageToken'] ?? null, 'estimate' => (int) ($list['resultSizeEstimate'] ?? count($rows))];
    }

    /** One message: headers, text and html bodies, attachments (names only). */
    public static function message(int $userId, string $id): array
    {
        $x = self::ok(Google::api($userId, 'GET', self::BASE.'/messages/'.rawurlencode($id), ['format' => 'full']));
        $h = self::headers($x['payload']['headers'] ?? []);
        $text = $html = '';
        $files = [];
        self::walk($x['payload'] ?? [], $text, $html, $files);

        return ['id' => (string) $x['id'], 'thread' => (string) ($x['threadId'] ?? ''), 'from' => $h['from'] ?? '', 'to' => $h['to'] ?? '', 'cc' => $h['cc'] ?? '', 'subject' => $h['subject'] ?? '', 'date' => self::date($h['date'] ?? '', $x['internalDate'] ?? null),
            'message_id' => $h['message-id'] ?? '', 'references' => $h['references'] ?? '', 'text' => $text, 'html' => $html, 'files' => $files, 'unread' => in_array('UNREAD', $x['labelIds'] ?? [], true)];
    }

    public static function send(int $userId, string $to, string $subject, string $body, ?string $threadId = null, ?string $inReplyTo = null, ?string $references = null, string $cc = ''): void
    {
        $from = (string) (Google::connection($userId)['google_email'] ?? '');
        $head = ['To: '.self::clean($to), 'Subject: =?UTF-8?B?'.base64_encode(self::clean($subject)).'?=', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
        if ($from !== '') { array_unshift($head, 'From: '.$from); }
        if ($cc !== '') { $head[] = 'Cc: '.self::clean($cc); }
        if ($inReplyTo) { $head[] = 'In-Reply-To: '.self::clean($inReplyTo); $head[] = 'References: '.self::clean(trim($references.' '.$inReplyTo)); }
        $raw = implode("\r\n", $head)."\r\n\r\n".chunk_split(base64_encode($body));
        self::ok(Google::api($userId, 'POST', self::BASE.'/messages/send', [], array_filter(['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), 'threadId' => $threadId])));
    }

    private static function clean(string $v): string { return trim(preg_replace('/[\r\n]+/', ' ', $v)); }

    private static function headers(array $list): array
    {
        $out = [];
        foreach ($list as $h) { $out[strtolower((string) $h['name'])] = (string) $h['value']; }

        return $out;
    }

    private static function date(string $header, mixed $internal): ?string
    {
        if ($internal) { return date('c', (int) ((int) $internal / 1000)); }

        return $header !== '' && ($t = strtotime($header)) ? date('c', $t) : null;
    }

    private static function walk(array $p, string &$text, string &$html, array &$files): void
    {
        $mime = (string) ($p['mimeType'] ?? '');
        $data = $p['body']['data'] ?? null;
        if (! empty($p['filename']) && ! empty($p['body']['attachmentId'])) { $files[] = ['name' => (string) $p['filename'], 'size' => (int) ($p['body']['size'] ?? 0), 'type' => $mime]; }
        elseif ($data && $mime === 'text/plain' && $text === '') { $text = (string) base64_decode(strtr($data, '-_', '+/')); }
        elseif ($data && $mime === 'text/html' && $html === '') { $html = (string) base64_decode(strtr($data, '-_', '+/')); }
        foreach ($p['parts'] ?? [] as $part) { self::walk($part, $text, $html, $files); }
    }

    /** Remove everything active from e-mail HTML: scripts, frames, forms, event handlers, javascript: links; remote images unless asked for. */
    public static function safeHtml(string $html, bool $images): string
    {
        $html = preg_replace('#<(script|iframe|object|embed|form|meta|link|base|style)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|iframe|object|embed|form|meta|link|base|input|button)\b[^>]*/?>#is', '', $html) ?? '';
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
        $html = preg_replace('#(href|src|action|background)\s*=\s*(["\']?)\s*(javascript|vbscript|data:text/html)[^"\'>\s]*#i', '$1=$2#', $html) ?? '';
        $html = preg_replace('#<a\s#i', '<a target="_blank" rel="noopener noreferrer" ', $html) ?? '';
        if (! $images) { $html = preg_replace('#<img\b[^>]*>#i', '', $html) ?? ''; }

        return $html;
    }
}
