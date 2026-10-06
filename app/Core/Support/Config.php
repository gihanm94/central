<?php
declare(strict_types=1);

namespace App\Core\Support;

final class Config
{
    private static array $items = [];

    public static function load(string $dir): void
    {
        foreach (glob($dir.'/*.php') as $file) {
            $name = basename($file, '.php');
            self::$items[$name === 'config' ? '_' : $name] = require $file;
        }
        // config.php keys (app, db, mail …) sit at the top level
        self::$items = array_merge(self::$items['_'] ?? [], self::$items);
        unset(self::$items['_']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$items;
        foreach (explode('.', $key) as $part) {
            $ref = &$ref[$part];
        }
        $ref = $value;
    }
}
