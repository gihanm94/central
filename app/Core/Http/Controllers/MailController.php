<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Google\Gmail;
use App\Core\Support\Google\Google;

/** Your own Gmail inside the system (read, search, reply, send). Mail stays at Google; nothing is copied here. */
class MailController extends Controller
{
    private const LABELS = ['INBOX' => 'Inbox', 'SENT' => 'Sent', 'STARRED' => 'Starred', 'DRAFT' => 'Drafts'];

    public function index(): string
    {
        $u = $this->user();
        $connected = Google::connected($u->id);
        $label = isset(self::LABELS[(string) Request::query('label')]) ? (string) Request::query('label') : 'INBOX';
        $q = trim((string) Request::query('q', ''));
        $data = ['rows' => [], 'next' => null, 'estimate' => 0];
        $error = null;
        if ($connected) {
            try { $data = Gmail::inbox($u->id, $label, $q, Request::query('t') ?: null); }
            catch (\Throwable $e) { $error = $e->getMessage(); }
        }

        return view('mail/index', ['title' => __('Mail'), 'connected' => $connected, 'configured' => Google::configured(), 'label' => $label, 'labels' => self::LABELS, 'q' => $q, 'data' => $data, 'error' => $error,
            'email' => Google::connection($u->id)['google_email'] ?? '', 'page' => max(1, (int) Request::query('p', 1))]);
    }

    public function show(string $mid): string
    {
        $u = $this->user();
        Google::connected($u->id) || redirect('/mail');
        try { $m = Gmail::message($u->id, $mid); }
        catch (\Throwable $e) { Session::flash('error', $e->getMessage()); redirect('/mail'); }
        $images = Request::query('images') === '1';
        $body = $m['html'] !== '' ? Gmail::safeHtml($m['html'], $images) : '<pre style="white-space:pre-wrap;font:14px/1.5 system-ui,sans-serif">'.e($m['text']).'</pre>';
        $doc = '<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src '.($images ? '* data:' : 'data:').'; style-src \'unsafe-inline\'"><base target="_blank"><style>body{font:14px/1.55 system-ui,sans-serif;color:#1b1c1f;margin:12px;word-wrap:break-word}img{max-width:100%;height:auto}blockquote{border-left:3px solid #ddd;margin:8px 0;padding-left:12px;color:#555}</style>'.$body;

        return view('mail/show', ['title' => $m['subject'] ?: __('Mail'), 'm' => $m, 'doc' => $doc, 'hasImages' => $m['html'] !== '' && preg_match('/<img\b/i', $m['html']) && ! $images]);
    }

    public function send(): never
    {
        $u = $this->user();
        $to = trim((string) Request::input('to'));
        $subject = trim((string) Request::input('subject'));
        $body = (string) Request::input('body');
        if (! filter_var(explode(',', $to)[0] === '' ? '' : trim(explode(',', $to)[0]), FILTER_VALIDATE_EMAIL) && ! preg_match('/<[^>]+@[^>]+>/', $to)) {
            Session::flash('error', __('Enter a valid e-mail address.'));
            back();
        }
        try {
            Gmail::send($u->id, $to, $subject, $body, (string) Request::input('thread') ?: null, (string) Request::input('in_reply_to') ?: null, (string) Request::input('references'), trim((string) Request::input('cc')));
            Session::flash('success', __('Message sent.'));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            back();
        }
        redirect('/mail?label=SENT');
    }
}
