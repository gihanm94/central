<?php
declare(strict_types=1);

use App\Core\Auth\Auth;
use App\Core\Support\Config;
use App\Core\Support\Session;
use App\Core\Support\View;

function config(string $key, mixed $default = null): mixed { return Config::get($key, $default); }

/** Escape for HTML output. */
function e(mixed $value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function is_https(): bool
{
    return (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function base_url(): string
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return rtrim((string) config('app.url'), '/');
    }
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $prefix = $script === '/' || $script === '.' ? '' : rtrim($script, '/');

    return (is_https() ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].$prefix;
}

function url(string $path = '/', array $query = []): string
{
    $q = array_filter($query, fn ($v) => $v !== null && $v !== '');

    return base_url().'/'.ltrim($path, '/').($q ? '?'.http_build_query($q) : '');
}

function asset(string $path): string
{
    $file = BASE_PATH.'/public/'.ltrim($path, '/');

    return url($path).(is_file($file) ? '?v='.filemtime($file) : '');
}

function upload_url(?string $path): ?string { return $path ? url('uploads/'.$path) : null; }

function csrf_token(): string { return Session::csrf(); }
function csrf_field(): string { return '<input type="hidden" name="_token" value="'.e(csrf_token()).'">'; }

function old(string $key, mixed $default = null): mixed { return Session::old($key, $default); }
function error_for(string $key): ?string { return Session::errors()[$key] ?? null; }
function field_error(string $key): string
{
    $msg = error_for($key);

    return $msg ? '<p class="error">'.e($msg).'</p>' : '';
}

function auth(): ?App\Core\Auth\CurrentUser { return Auth::user(); }

/** Does the signed-in user hold <action> on <resource>? */
function can(string $resource, string $action = 'view'): bool { return (bool) auth()?->can($resource, $action); }

function view(string $template, array $data = []): string { return View::render($template, $data); }
function partial(string $template, array $data = []): string { return View::partial($template, $data); }
function icon(string $name, string $class = 'size-5'): string { return View::icon($name, $class); }

function now(): string { return date('Y-m-d H:i:s'); }

/** Translate. Keys are English text: __('Sign in'), __('Hello :name', ['name' => $n]). */
function __(string $key, array $params = []): string { return App\Core\Support\I18n::translate($key, $params); }

function locale(): string { return App\Core\Support\I18n::locale(); }

/** Render a stored activity text ['key' => ..., 'params' => ...] in the current language. */
function activity_render(array $t): string
{
    $params = $t['params'] ?? [];
    if (isset($params['type'])) {
        $params['type'] = __((string) $params['type']);
    }

    return __((string) ($t['key'] ?? ''), $params);
}

/** Description of a log row in the reader's language (older rows fall back to stored English). */
function activity_text(array $log): string
{
    $p = is_array($log['properties'] ?? null) ? $log['properties'] : (json_decode((string) ($log['properties'] ?? ''), true) ?: []);

    return isset($p['_t']) ? activity_render($p['_t']) : (string) $log['description'];
}

/** Dates with month and day names in the current language. */
function format_date(?string $value, string $format = 'd M Y'): string
{
    if (! $value) {
        return '—';
    }
    $out = date($format, strtotime($value));
    if (locale() === 'en') {
        return $out;
    }

    return preg_replace_callback('/\b(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/',
        fn ($m) => __($m[1]), $out);
}

function time_ago(?string $value): string
{
    if (! $value) {
        return __('never');
    }
    $diff = time() - strtotime($value);
    if ($diff < 0) {
        return __('in :time', ['time' => time_span(-$diff)]);
    }

    return $diff < 45 ? __('just now') : __(':time ago', ['time' => time_span($diff)]);
}

function time_span(int $s): string
{
    foreach ([31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'] as $sec => $unit) {
        if ($s >= $sec) {
            $n = intdiv($s, $sec);

            return __($n > 1 ? ':n '.$unit.'s' : ':n '.$unit, ['n' => $n]);
        }
    }

    return __(':n seconds', ['n' => $s]);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [''];

    return strtoupper(mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
}

function str_limit(?string $value, int $limit = 60): string
{
    $value = (string) $value;

    return mb_strlen($value) > $limit ? rtrim(mb_substr($value, 0, $limit - 1)).'…' : $value;
}

function number_clean(mixed $n): string { return rtrim(rtrim(number_format((float) $n, 2, '.', ','), '0'), '.'); }

function setting(string $key, mixed $default = null): mixed { return App\Core\Support\Settings::get($key, $default); }

function abort(int $code, string $message = ''): never { throw new App\Core\Support\HttpException($code, $message); }

function redirect(string $to): never
{
    header('Location: '.(str_starts_with($to, 'http') ? $to : url($to)), true, 302);
    exit;
}

/** Go back to the previous page (same site only), with optional flash message. */
function back(?string $type = null, ?string $message = null): never
{
    if ($type) {
        App\Core\Support\Session::flash($type, $message);
    }
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    redirect($ref && str_starts_with($ref, base_url()) ? $ref : '/dashboard');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Tailwind dropdown (no native <select>). Same name/value posting as a select.
 *   select_field('status', $options, $current, ['placeholder' => __('All statuses'), 'submit' => true])
 * Options: placeholder, id, submit (submit form on change), disabled, class (button classes),
 *          search (bool, default when > 8 options), tones [value => classes], size ('sm')
 */
function select_field(string $name, array $options, mixed $value = null, array $opts = []): string
{
    return partial('partials/select', ['name' => $name, 'options' => $options, 'value' => $value === null ? '' : (string) $value, 'o' => $opts]);
}

/** Enum label helpers for employment data. */
function enum_label(string $group, ?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $map = config('core.employee.'.$group, []);

    return array_is_list($map) ? ($value === 'OTHER' ? __('Other') : $value) : __($map[$value] ?? $value);
}

function enum_options(string $group): array
{
    $map = config('core.employee.'.$group, []);
    if (array_is_list($map)) {
        return array_combine($map, array_map(fn ($v) => $v === 'OTHER' ? __('Other') : $v, $map));
    }

    return array_map(fn ($l) => __($l), $map);
}
