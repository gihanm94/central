<?php
/*
 | One-time installer. Open http://localhost:8000/install.php
 | Creates the PostgreSQL databases, tables, roles, permissions, your admin
 | account and (optionally) demo data, then locks itself.
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
$lock   = BASE_PATH.'/storage/installed.lock';
$config = require BASE_PATH.'/config/config.php';
$sqlDir = BASE_PATH.'/database/sql';
session_start();

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** Same Tailwind dropdown as the app (no native <select>). */
function dropdown(string $name, array $options, string $value, string $id): string
{
    $label = $options[$value] ?? reset($options);
    $items = '';
    foreach ($options as $k => $l) {
        $sel = (string) $k === $value ? 'true' : 'false';
        $items .= '<button type="button" role="option" data-value="'.h($k).'" data-label="'.h($l).'" aria-selected="'.$sel.'" class="flex w-full items-center rounded-md px-2.5 py-1.5 text-left text-sm hover:bg-mist focus:bg-mist focus:outline-none aria-selected:font-medium">'.h($l).'</button>';
    }

    return '<div class="relative" data-select><input type="hidden" name="'.h($name).'" value="'.h($value).'" data-select-input>'
        .'<button type="button" id="'.h($id).'" data-select-button data-base="input" aria-haspopup="listbox" aria-expanded="false" class="input flex w-full items-center justify-between gap-2 text-left"><span data-select-label class="truncate">'.h($label).'</span><span aria-hidden="true" class="text-graphite-400">&#8693;</span></button>'
        .'<div data-select-panel hidden role="listbox" class="fixed z-[70] min-w-44 rounded-lg bg-white p-1 shadow-xl ring-1 ring-graphite-900/10"><div class="max-h-64 overflow-y-auto">'.$items.'</div></div></div>';
}

$dbFiles = [
    'core' => 'user_db.sql', 'crm' => 'crm_db.sql', 'accounting' => 'account_db.sql',
    'inventory' => 'inventory_db.sql', 'machines' => 'machine_db.sql', 'hr' => 'hr_db.sql',
];

// ---------------------------------------------------------------- checks
$checks = [
    'PHP 8.1 or newer'               => version_compare(PHP_VERSION, '8.1.0', '>='),
    'pdo_pgsql extension'            => extension_loaded('pdo_pgsql'),
    'openssl extension'              => extension_loaded('openssl'),
    'fileinfo extension'             => extension_loaded('fileinfo'),
    'mbstring extension'             => extension_loaded('mbstring'),
    'config/ is writable'            => is_writable(BASE_PATH.'/config'),
    'storage/ is writable'           => is_writable(BASE_PATH.'/storage'),
    'public/uploads/ is writable'    => is_writable(BASE_PATH.'/public/uploads'),
];
$ready = ! in_array(false, $checks, true);

$errors = [];
$log    = [];
$done   = false;
$_SESSION['install_token'] ??= bin2hex(random_bytes(16));

$v = [
    'company'   => $_POST['company'] ?? $config['app']['name'],
    'app_url'   => $_POST['app_url'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost:8000')),
    'host'      => $_POST['host'] ?? $config['db']['host'],
    'port'      => $_POST['port'] ?? $config['db']['port'],
    'user'      => $_POST['user'] ?? $config['db']['username'],
    'pass'      => $_POST['pass'] ?? $config['db']['password'],
    'admin'     => $_POST['admin'] ?? 'System Admin',
    'email'     => $_POST['email'] ?? 'admin@acmeinter.com',
    'demo'      => isset($_POST['company']) ? isset($_POST['demo']) : true,
    'mail'      => $_POST['mail'] ?? $config['mail']['driver'],
    'smtp_host' => $_POST['smtp_host'] ?? $config['mail']['host'],
    'smtp_port' => $_POST['smtp_port'] ?? $config['mail']['port'],
    'smtp_enc'  => $_POST['smtp_enc'] ?? $config['mail']['encryption'],
    'smtp_user' => $_POST['smtp_user'] ?? $config['mail']['username'],
    'smtp_pass' => $_POST['smtp_pass'] ?? $config['mail']['password'],
    'from'      => $_POST['from'] ?? $config['mail']['from_address'],
    'locale'    => $_POST['locale'] ?? 'en',
];
foreach ($config['db']['databases'] as $key => $name) {
    $v['db_'.$key] = $_POST['db_'.$key] ?? $name;
}

// ------------------------------------------------------------- install
if (! is_file($lock) && $_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    if (! hash_equals($_SESSION['install_token'], (string) ($_POST['_token'] ?? ''))) {
        $errors[] = 'The form expired. Reload the page and try again.';
    }
    $password = (string) ($_POST['password'] ?? '');
    if (trim($v['company']) === '') { $errors[] = 'Enter your company name.'; }
    if (! filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid admin e-mail.'; }
    if (! preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) { $errors[] = 'Admin password needs 8+ characters with upper and lower case letters and a number.'; }
    if ($password !== ($_POST['password_confirmation'] ?? '')) { $errors[] = 'The two admin passwords do not match.'; }
    foreach ($config['db']['databases'] as $key => $_) {
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $v['db_'.$key])) { $errors[] = 'Database names may only use lower-case letters, numbers and _.'; break; }
    }

    if (! $errors) {
        try {
            $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
            $dsn  = fn ($db) => "pgsql:host={$v['host']};port={$v['port']};dbname={$db}";

            // 1. Server connection
            $server = null;
            foreach (['postgres', 'template1', $v['db_core']] as $maint) {
                try { $server = new PDO($dsn($maint), $v['user'], $v['pass'], $opts); break; } catch (PDOException $e) { $last = $e; }
            }
            if (! $server) {
                throw new RuntimeException('Could not connect to PostgreSQL as "'.$v['user'].'": '.$last->getMessage());
            }
            $log[] = 'Connected to PostgreSQL '.$server->query('SHOW server_version')->fetchColumn();

            // 2. Databases
            foreach ($config['db']['databases'] as $key => $_) {
                $name = $v['db_'.$key];
                $exists = $server->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
                $exists->execute([$name]);
                if ($exists->fetchColumn()) {
                    $log[] = "Database {$name} already exists — keeping it";
                } else {
                    try {
                        $server->exec('CREATE DATABASE "'.$name.'" ENCODING \'UTF8\'');
                        $log[] = "Created database {$name}";
                    } catch (PDOException $e) {
                        throw new RuntimeException("Could not create database {$name}. Give the user permission first (run database/sql/00_create_databases.sql as the postgres superuser), or create the database yourself. Details: ".$e->getMessage());
                    }
                }
            }
            $server = null;

            // 3. Tables
            $dbConns = [];
            foreach ($dbFiles as $key => $file) {
                $pdo = new PDO($dsn($v['db_'.$key]), $v['user'], $v['pass'], $opts);
                $pdo->exec(file_get_contents($sqlDir.'/'.$file));
                $log[] = 'Tables ready in '.$v['db_'.$key];
                if ($key === 'core') { $core = $pdo; }
                $dbConns[$key] = $pdo;
            }

            // 4. Upgrades (database/sql/migrations) — new columns, settings …
            require_once BASE_PATH.'/app/Core/Support/Migrator.php';
            foreach (App\Core\Support\Migrator::run(fn ($key) => new PDO($dsn($v['db_'.$key] ?? $key), $v['user'], $v['pass'], $opts)) as $m) {
                $log[] = 'Applied '.h($m);
            }
            @file_put_contents(BASE_PATH.'/storage/cache/schema.version', App\Core\Support\Migrator::fingerprint());

            // 5. Core data
            $core->exec(file_get_contents($sqlDir.'/user_db_seed.sql'));
            $log[] = 'Roles, permissions and settings added';
            if ($v['demo']) {
                $core->exec(file_get_contents($sqlDir.'/user_db_demo.sql'));
                $log[] = 'Demo departments, teams, people, tasks and KPIs added';
                if (isset($dbConns['crm'])) {
                    $dbConns['crm']->exec(file_get_contents($sqlDir.'/crm_db_demo.sql'));
                    $log[] = 'Demo CRM leads, contacts, opportunities, activities and campaigns added';
                }
            }
            $stmt = $core->prepare('UPDATE users SET name = ?, email = ?, password = ?, is_active = TRUE, deleted_at = NULL, updated_at = now() WHERE id = 1');
            $stmt->execute([trim($v['admin']) ?: 'System Admin', strtolower(trim($v['email'])), password_hash($password, PASSWORD_DEFAULT)]);
            $core->prepare("UPDATE settings SET value = ? WHERE key = 'company_name'")->execute([trim($v['company'])]);
            $locale = in_array($v['locale'], ['en', 'th'], true) ? $v['locale'] : 'en';
            $core->prepare("INSERT INTO settings (key, value) VALUES ('default_locale', ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value")->execute([$locale]);
            $core->exec("INSERT INTO employee_profiles (user_id) SELECT id FROM users u WHERE NOT EXISTS (SELECT 1 FROM employee_profiles p WHERE p.user_id = u.id)");
            $log[] = 'Admin account '.h($v['email']).' ready';

            // 6. Config file
            $config['app']['name'] = trim($v['company']);
            $config['app']['url']  = rtrim($v['app_url'], '/');
            if (str_starts_with((string) $config['app']['key'], 'change-me')) {
                $config['app']['key'] = bin2hex(random_bytes(32));
            }
            $config['db'] = array_merge($config['db'], ['host' => $v['host'], 'port' => (int) $v['port'], 'username' => $v['user'], 'password' => $v['pass']]);
            foreach ($config['db']['databases'] as $key => $_) {
                $config['db']['databases'][$key] = $v['db_'.$key];
            }
            $config['mail'] = array_merge($config['mail'], [
                'driver' => $v['mail'] === 'smtp' ? 'smtp' : 'log', 'host' => $v['smtp_host'], 'port' => (int) $v['smtp_port'],
                'encryption' => in_array($v['smtp_enc'], ['tls', 'ssl', 'none'], true) ? $v['smtp_enc'] : 'tls',
                'username' => $v['smtp_user'], 'password' => $v['smtp_pass'], 'from_address' => $v['from'], 'from_name' => trim($v['company']),
            ]);
            file_put_contents(BASE_PATH.'/config/config.php', "<?php\n// Written by public/install.php on ".date('Y-m-d H:i')." — edit freely.\nreturn ".var_export($config, true).";\n");
            $log[] = 'Saved config/config.php';

            file_put_contents($lock, date('c'));
            $done = true;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$installed = is_file($lock);
$css = 'assets/app.css?v='.(is_file(__DIR__.'/assets/app.css') ? filemtime(__DIR__.'/assets/app.css') : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · <?= h($v['company']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=IBM+Plex+Sans+Thai:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $css ?>">
<script src="assets/app.js" defer></script>
</head>
<body class="min-h-screen bg-mist">
<header class="bg-graphite-900 text-white">
    <div class="mx-auto flex max-w-4xl items-center gap-3 px-6 py-5">
        <span class="flex size-9 items-center justify-center rounded bg-signal-600 font-display text-lg font-bold">A</span>
        <div><p class="font-display text-2xl font-semibold leading-none">Install the company system</p><p class="mt-1 text-sm text-graphite-300">Sets up PostgreSQL, the core tables and your admin account.</p></div>
    </div>
    <div class="h-1 bg-signal-600"></div>
</header>

<main class="mx-auto max-w-4xl px-6 py-8">
<?php if ($done || $installed): ?>
    <section class="panel p-6">
        <h1 class="page-title"><?= $done ? 'Installed' : 'Already installed' ?></h1>
        <?php if ($log): ?><ul class="mt-4 space-y-1.5 text-sm"><?php foreach ($log as $l): ?><li class="flex gap-2"><span class="text-emerald-600">&#10003;</span><?= $l ?></li><?php endforeach ?></ul><?php endif ?>
        <?php if ($done && $v['demo']): ?>
            <div class="mt-6 rounded-md bg-mist p-4 text-sm">
                <p class="font-medium">Demo accounts (password <code>Password@123</code>)</p>
                <p class="mt-1 text-steel">management@acmeinter.com · bu.manager@acmeinter.com · manager@acmeinter.com · member@acmeinter.com</p>
            </div>
        <?php endif ?>
        <p class="mt-6 text-sm text-steel">The installer is now locked. To run it again, delete <code>storage/installed.lock</code>. For a live server, also delete <code>public/install.php</code>.</p>
        <a href="index.php" class="btn-primary mt-6">Go to sign in</a>
    </section>
<?php else: ?>
    <section class="panel p-6">
        <h2 class="panel-title">Server check</h2>
        <ul class="mt-3 grid gap-1.5 text-sm sm:grid-cols-2">
            <?php foreach ($checks as $label => $ok): ?>
                <li class="flex items-center gap-2"><span class="<?= $ok ? 'text-emerald-600' : 'text-signal-600' ?>"><?= $ok ? '&#10003;' : '&#10007;' ?></span><?= h($label) ?></li>
            <?php endforeach ?>
        </ul>
        <?php if (! $checks['pdo_pgsql extension']): ?><p class="mt-3 text-sm text-signal-700">Enable <code>extension=pdo_pgsql</code> in php.ini (Homebrew PHP on Mac includes it: <code>brew install php</code>).</p><?php endif ?>
    </section>

    <?php if ($errors): ?>
        <div class="mt-6 rounded-md border-l-4 border-signal-600 bg-signal-50 px-4 py-3 text-sm text-signal-800"><?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach ?></div>
        <?php if ($log): ?><ul class="mt-3 space-y-1 text-sm text-steel"><?php foreach ($log as $l): ?><li>&#10003; <?= $l ?></li><?php endforeach ?></ul><?php endif ?>
    <?php endif ?>

    <form method="POST" class="mt-6 space-y-6">
        <input type="hidden" name="_token" value="<?= h($_SESSION['install_token']) ?>">

        <fieldset class="panel">
            <div class="panel-head"><h2 class="panel-title">Company and admin account</h2></div>
            <div class="grid gap-5 p-6 sm:grid-cols-2">
                <div><label class="label" for="company">Company name</label><input class="input" id="company" name="company" value="<?= h($v['company']) ?>" required></div>
                <div><label class="label" for="locale">Default language</label><?= dropdown('locale', ['en' => 'English', 'th' => 'ไทย (Thai)'], (string) $v['locale'], 'locale') ?><p class="hint">People can choose their own later.</p></div>
                <div><label class="label" for="app_url">Site address</label><input class="input" id="app_url" name="app_url" value="<?= h($v['app_url']) ?>" required><p class="hint">Used in e-mail links.</p></div>
                <div><label class="label" for="admin">Admin name</label><input class="input" id="admin" name="admin" value="<?= h($v['admin']) ?>" required></div>
                <div><label class="label" for="email">Admin e-mail (you sign in with this)</label><input class="input" id="email" name="email" type="email" value="<?= h($v['email']) ?>" required></div>
                <div><label class="label" for="password">Admin password</label><input class="input" id="password" name="password" type="password" required autocomplete="new-password"><p class="hint">8+ characters, upper and lower case and a number.</p></div>
                <div><label class="label" for="password_confirmation">Repeat password</label><input class="input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
                <label class="flex items-start gap-3 text-sm sm:col-span-2"><input type="checkbox" name="demo" value="1" class="mt-0.5 size-4 accent-signal-600" <?= $v['demo'] ? 'checked' : '' ?>><span><span class="font-medium">Add demo data</span><span class="block text-steel">Sample departments, teams, one person per role, tasks and KPIs, so you can see how each role works. Untick for a clean start.</span></span></label>
            </div>
        </fieldset>

        <fieldset class="panel">
            <div class="panel-head"><h2 class="panel-title">PostgreSQL</h2></div>
            <div class="grid gap-5 p-6 sm:grid-cols-4">
                <div class="sm:col-span-2"><label class="label" for="host">Host</label><input class="input" id="host" name="host" value="<?= h($v['host']) ?>" required></div>
                <div><label class="label" for="port">Port</label><input class="input" id="port" name="port" value="<?= h($v['port']) ?>" required></div>
                <div></div>
                <div class="sm:col-span-2"><label class="label" for="user">Username</label><input class="input" id="user" name="user" value="<?= h($v['user']) ?>" required></div>
                <div class="sm:col-span-2"><label class="label" for="pass">Password</label><input class="input" id="pass" name="pass" type="password" value="<?= h($v['pass']) ?>"></div>
            </div>
            <div class="border-t border-graphite-900/8 px-6 py-5">
                <p class="text-sm font-medium">Databases (created if missing)</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-3">
                    <?php foreach (['core' => 'Core: people, roles, logs', 'crm' => 'CRM', 'accounting' => 'Accounting', 'inventory' => 'Inventory', 'machines' => 'Machines', 'hr' => 'HR'] as $key => $label): ?>
                        <div><label class="label" for="db_<?= $key ?>"><?= $label ?></label><input class="input" id="db_<?= $key ?>" name="db_<?= $key ?>" value="<?= h($v['db_'.$key]) ?>" required></div>
                    <?php endforeach ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="panel">
            <div class="panel-head"><h2 class="panel-title">E-mail</h2></div>
            <div class="grid gap-5 p-6 sm:grid-cols-4">
                <div class="sm:col-span-4 flex flex-wrap gap-6 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="mail" value="log" class="accent-signal-600" <?= $v['mail'] !== 'smtp' ? 'checked' : '' ?>> Save e-mails to files (storage/mail) — good for testing</label>
                    <label class="flex items-center gap-2"><input type="radio" name="mail" value="smtp" class="accent-signal-600" <?= $v['mail'] === 'smtp' ? 'checked' : '' ?>> Send with SMTP</label>
                </div>
                <div class="sm:col-span-2"><label class="label" for="smtp_host">SMTP host</label><input class="input" id="smtp_host" name="smtp_host" value="<?= h($v['smtp_host']) ?>"></div>
                <div><label class="label" for="smtp_port">Port</label><input class="input" id="smtp_port" name="smtp_port" value="<?= h($v['smtp_port']) ?>"></div>
                <div><label class="label" for="smtp_enc">Security</label><?= dropdown('smtp_enc', ['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'], (string) $v['smtp_enc'], 'smtp_enc') ?></div>
                <div class="sm:col-span-2"><label class="label" for="smtp_user">SMTP username</label><input class="input" id="smtp_user" name="smtp_user" value="<?= h($v['smtp_user']) ?>" autocomplete="off"></div>
                <div class="sm:col-span-2"><label class="label" for="smtp_pass">SMTP password</label><input class="input" id="smtp_pass" name="smtp_pass" type="password" value="<?= h($v['smtp_pass']) ?>" autocomplete="off"><p class="hint">For Gmail use an App Password.</p></div>
                <div class="sm:col-span-2"><label class="label" for="from">Send from address</label><input class="input" id="from" name="from" type="email" value="<?= h($v['from']) ?>"></div>
            </div>
        </fieldset>

        <div class="flex items-center justify-end gap-4">
            <p class="text-sm text-steel">Takes a few seconds. Safe to run again: existing data is kept.</p>
            <button class="btn-primary px-6 py-2.5" <?= $ready ? '' : 'disabled' ?>>Install now</button>
        </div>
    </form>
<?php endif ?>
</main>
</body>
</html>
