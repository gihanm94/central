<?php
// Converts the PHP config arrays (config/*.php) and translations (lang/*.php) to the JSON the Go app reads.
//   php tools/php-to-json.php            → config/core.json, modules.json, erp.json, erp_schema.json, inet_schema.json, lang/*.json
//   php tools/php-to-json.php config     → also config/config.json from config/config.php (holds passwords: never commit it)
define('BASE_PATH', dirname(__DIR__));
function __(string $k, array $p = []): string { return $k; }
$out = function (string $file, mixed $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n");
    echo "wrote {$file}\n";
};
foreach (['core', 'modules', 'erp', 'erp_schema', 'inet_schema'] as $n) {
    if (is_file(BASE_PATH."/config/{$n}.php")) { $out(BASE_PATH."/config/{$n}.json", require BASE_PATH."/config/{$n}.php"); }
}
foreach (glob(BASE_PATH.'/lang/*.php') ?: [] as $f) { $out(substr($f, 0, -4).'.json', (object) require $f); }
if (($argv[1] ?? '') === 'config' && is_file(BASE_PATH.'/config/config.php')) {
    $out(BASE_PATH.'/config/config.json', require BASE_PATH.'/config/config.php');
    @chmod(BASE_PATH.'/config/config.json', 0600);
}
