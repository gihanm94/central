<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Plain-PHP templates in resources/views.
 * A page may set $layout (default 'layouts/app'), $title and $breadcrumb;
 * the layout receives $content plus every variable the page defined.
 */
final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void { self::$shared[$key] = $value; }

    public static function render(string $__template, array $__data = []): string
    {
        extract(self::$shared);
        extract($__data);
        $layout = 'layouts/app';

        ob_start();
        try {
            include self::path($__template);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $content = ob_get_clean();

        if (! $layout) {
            return $content;
        }

        ob_start();
        include self::path($layout);

        return ob_get_clean();
    }

    public static function partial(string $__template, array $__data = []): string
    {
        extract(self::$shared);
        extract($__data);
        ob_start();
        include self::path($__template);

        return ob_get_clean();
    }

    private static function path(string $template): string
    {
        $file = BASE_PATH.'/resources/views/'.$template.'.php';
        if (! is_file($file)) {
            throw new \RuntimeException("View [{$template}] not found.");
        }

        return $file;
    }

    /** Outline icons (Heroicons, MIT). */
    public static function icon(string $name, string $class = 'size-5'): string
    {
        static $paths = null;
        $paths ??= require BASE_PATH.'/resources/views/partials/icons.php';
        $d = $paths[$name] ?? $paths['grid'];

        return '<svg class="'.e($class).'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="'.$d.'"/></svg>';
    }
}
