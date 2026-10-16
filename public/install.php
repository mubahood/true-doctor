<?php

/**
 * One-time web setup for a standalone release. This file runs before Laravel:
 * there is no .env, APP_KEY or database session yet. The application itself
 * takes over after the environment file has been written and verified.
 */

// Beside the web root, or beside a folder above it (a nested cPanel addon
// domain root such as public_html/hms.example.com) — the same search as the
// front controller in deploy/docroot-index.php.
$candidates = [dirname(__DIR__)];
for ($dir = __DIR__, $i = 0; $i < 4 && dirname($dir) !== $dir; $i++) {
    $dir = dirname($dir);
    $candidates[] = $dir.'/true_doctor_app';
}
$root = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate.'/vendor/autoload.php') && is_file($candidate.'/bootstrap/app.php')) {
        $root = realpath($candidate);
        break;
    }
}
if ($root === null) {
    http_response_code(500);
    exit('True-Doctor application files were not found. Follow the folder layout in INSTALL.md.');
}

require $root.'/vendor/autoload.php';

// Who to ask for help: written into the package at build time (SUPPORT.json),
// because nothing here can read the settings yet.
$support = is_file($root.'/SUPPORT.json') ? json_decode((string) file_get_contents($root.'/SUPPORT.json'), true) : null;
$supportLink = is_array($support) && ! empty($support['whatsapp'])
    ? 'https://wa.me/'.preg_replace('/\D/', '', (string) $support['whatsapp']).'?text='.rawurlencode((string) ($support['install_message'] ?? ''))
    : null;

$marker = $root.'/storage/app/private/installed.json';
$draftPath = $root.'/storage/app/private/install-draft.dat';
$environmentFile = $root.'/.env';
$existingEnvironment = is_file($environmentFile)
    ? \Dotenv\Dotenv::parse((string) file_get_contents($environmentFile))
    : [];

// The installer must never become a second entry point on the SaaS server.
if (($existingEnvironment['APP_EDITION'] ?? 'saas') !== 'standalone' && is_file($environmentFile)) {
    http_response_code(404);
    exit;
}

$installed = is_file($marker);
if ($installed && empty($_COOKIE['td_preinstall'])) {
    http_response_code(404);
    exit;
}

$secure = ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$local = str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
session_name('td_preinstall');
$sessionDirectory = $root.'/storage/framework/sessions';
if (! is_dir($sessionDirectory) && ! mkdir($sessionDirectory, 0700, true) && ! is_dir($sessionDirectory)) {
    http_response_code(500);
    exit('The session folder is not writable. Check the application folder permissions.');
}
session_save_path($sessionDirectory);
session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Strict', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));

if ($installed && empty($_SESSION['just_installed'])) {
    http_response_code(404);
    exit;
}
if (! $installed && is_file($draftPath) && empty($_SESSION['install_token'])) {
    http_response_code(403);
    exit('Installation is already in progress in another browser. Continue there or contact support.');
}
if (! $installed && is_file($environmentFile) && ! is_file($draftPath)) {
    http_response_code(403);
    exit('This server has an unfinished or manually configured environment. Contact support before restarting setup.');
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errors = [];
$values = [
    'app_url' => ($secure ? 'https://' : 'http://').$host,
    'db_host' => 'localhost', 'db_port' => '3306',
    'hospital_name' => '', 'currency' => 'UGX', 'timezone' => 'Africa/Kampala',
    'owner_name' => '', 'owner_email' => '', 'mail_host' => '', 'mail_port' => '465',
    'mail_user' => '', 'mail_from' => '',
];
foreach ($values as $key => $default) {
    if (isset($_POST[$key]) && is_string($_POST[$key])) {
        $values[$key] = trim($_POST[$key]);
    }
}

$requiredExtensions = ['bcmath', 'ctype', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'sodium', 'tokenizer', 'xml', 'zip'];
$missingExtensions = array_values(array_filter($requiredExtensions, static fn (string $extension): bool => ! extension_loaded($extension)));
$ready = PHP_VERSION_ID >= 80200 && $missingExtensions === []
    && is_writable($root) && is_writable($root.'/storage')
    && is_writable($root.'/bootstrap/cache') && ($secure || $local);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $installed) {
    $lockDirectory = $root.'/storage/app/private';
    if (! is_dir($lockDirectory) && ! mkdir($lockDirectory, 0700, true) && ! is_dir($lockDirectory)) {
        $errors[] = 'Private storage is not writable.';
    }
    $installLock = $errors === [] ? @fopen($lockDirectory.'/install.lock', 'c') : false;
    if ($installLock === false || ! flock($installLock, LOCK_EX | LOCK_NB)) {
        $errors[] = 'Another installation request is in progress. Wait for it to finish.';
    }
    if (is_file($marker)) {
        $errors[] = 'This installation has already completed.';
    }
    if (! hash_equals((string) $_SESSION['csrf'], (string) ($_POST['_token'] ?? ''))) {
        $errors[] = 'The form expired. Refresh this page and try again.';
    }
    if (! $ready) {
        $errors[] = 'Resolve the server checks below before installing.';
    }
    if (is_file($draftPath)) {
        if ($errors === []) {
            try {
                @set_time_limit(0);
                $details = (new \App\Services\Install\InstallDraft)->load(
                    $draftPath,
                    (string) ($existingEnvironment['APP_KEY'] ?? ''),
                    (string) $_SESSION['install_token'],
                );
                $app = require $root.'/bootstrap/app.php';
                $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                $result = $app->make(\App\Services\Install\StandaloneInstaller::class)->advance($details);
                $_SESSION['progress_completed'] = $result['completed'];
                $_SESSION['progress_total'] = $result['total'];
                if ($result['done']) {
                    \Illuminate\Support\Facades\Artisan::call('storage:link');
                    if (($existingEnvironment['MAIL_MAILER'] ?? 'log') === 'smtp') {
                        try {
                            \Illuminate\Support\Facades\Mail::raw('True-Doctor email is working. Your standalone installation is ready.', function ($message) use ($details): void {
                                $message->to($details['owner_email'])->subject('True-Doctor installation email test');
                            });
                            $_SESSION['mail_test'] = 'A test email was sent to the owner.';
                        } catch (\Throwable) {
                            $_SESSION['mail_test'] = 'The installation is ready, but the SMTP test failed. Check mail settings before inviting staff.';
                        }
                    }
                    @unlink($draftPath);
                    unset($_SESSION['install_token']);
                    $_SESSION['just_installed'] = true;
                    header('Location: /install.php?complete=1', true, 303);
                    exit;
                }
                header('Location: /install.php?step=1', true, 303);
                exit;
            } catch (\Throwable $exception) {
                error_log('True-Doctor installation step failed: '.get_class($exception).': '.$exception->getMessage());
                $errors[] = 'This setup step stopped. You can retry it; if it fails again, check the private application log or contact support before changing the database.';
            }
        }
    } else {
    if (! filter_var($values['app_url'], FILTER_VALIDATE_URL)
        || ! in_array(parse_url($values['app_url'], PHP_URL_SCHEME), $local ? ['http', 'https'] : ['https'], true)
        || parse_url($values['app_url'], PHP_URL_PATH) !== null
        || parse_url($values['app_url'], PHP_URL_HOST) !== parse_url(($secure ? 'https://' : 'http://').$host, PHP_URL_HOST)) {
        $errors[] = 'Enter this server’s exact HTTPS address, without a path.';
    }
    if (! preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', (string) ($_POST['db_name'] ?? ''))
        || ! preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', (string) ($_POST['db_user'] ?? ''))
        || ! preg_match('/^[a-zA-Z0-9_.-]{1,253}$/', $values['db_host'])
        || filter_var($values['db_port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
        $errors[] = 'Check the database host, port, name and user.';
    }
    if (mb_strlen($values['hospital_name']) < 2 || mb_strlen($values['hospital_name']) > 190
        || mb_strlen($values['owner_name']) < 2 || mb_strlen($values['owner_name']) > 190
        || ! filter_var($values['owner_email'], FILTER_VALIDATE_EMAIL)
        || strlen((string) ($_POST['owner_password'] ?? '')) < 12
        || ! preg_match('/^[A-Z]{3}$/', $values['currency'])
        || ! in_array($values['timezone'], timezone_identifiers_list(), true)) {
        $errors[] = 'Complete the organisation and owner fields. The owner password needs at least 12 characters.';
    }
    if ($values['mail_host'] !== '' && (! filter_var($values['mail_from'], FILTER_VALIDATE_EMAIL)
        || filter_var($values['mail_port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false)) {
        $errors[] = 'When SMTP is entered, provide a valid sender address and port.';
    }

    $licence = null;
    $upload = $_FILES['licence'] ?? null;
    if (! is_array($upload) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK
        || ($upload['size'] ?? 0) > 65536 || ! is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        $errors[] = 'Upload the licence.json file from your True-Doctor download page.';
    } else {
        try {
            $licence = json_decode((string) file_get_contents($upload['tmp_name']), true, 32, JSON_THROW_ON_ERROR);
            // Laravel is not running yet, so the keys this release trusts are
            // read from the file the release carries. The edition config file
            // asks the application for its storage folder, and there is no
            // application yet — every buyer's licence was once refused here.
            $trusted = is_file($root.'/RELEASE_KEYS.json')
                ? json_decode((string) file_get_contents($root.'/RELEASE_KEYS.json'), true)
                : null;
            if (! is_array($trusted) || $trusted === []) {
                throw new \RuntimeException('This package has no RELEASE_KEYS.json. Download the release again from your buyer portal.');
            }
            $verifier = new \App\Support\Licensing\Verifier($trusted);
            (new \App\Services\Releases\StandaloneArchive)->verifyExtracted($root, __DIR__, $verifier);
            if (! is_array($licence) || ! $verifier->verifiesDocument($licence)
                || ($licence['licence']['product'] ?? null) !== 'standalone-hospital'
                || ($licence['licence']['format'] ?? null) !== 1
                || ! is_array($licence['licence']['domains'] ?? null)
                || ! in_array(
                    strtolower((string) parse_url($values['app_url'], PHP_URL_HOST)),
                    array_map('strtolower', array_filter($licence['licence']['domains'], 'is_string')),
                    true,
                )) {
                $errors[] = 'The uploaded licence could not be verified.';
            }
        } catch (\RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        } catch (\Throwable $exception) {
            error_log('True-Doctor installer could not read the licence: '.get_class($exception).': '.$exception->getMessage());
            $errors[] = 'The uploaded licence is not a valid True-Doctor licence file.';
        }
    }

    if ($errors === []) {
        try {
            $databaseName = (string) $_POST['db_name'];
            $databaseUser = (string) $_POST['db_user'];
            $databasePassword = (string) ($_POST['db_password'] ?? '');
            $pdo = new \PDO(
                "mysql:host={$values['db_host']};port={$values['db_port']};dbname={$databaseName};charset=utf8mb4",
                $databaseUser,
                $databasePassword,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5],
            );
            if ($pdo->query('SHOW TABLES')->fetch() !== false) {
                throw new \DomainException('This database already has tables. Create an empty database for this installation.');
            }
            // Every table is created as InnoDB (config/database.php), whatever the server default.
            $innodb = $pdo->query("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'")->fetchColumn();
            if (! in_array(strtoupper((string) $innodb), ['YES', 'DEFAULT'], true)) {
                throw new \DomainException('This MySQL server has the InnoDB storage engine switched off. Ask your host to enable InnoDB, then try again.');
            }
        } catch (\DomainException $e) {
            $errors[] = $e->getMessage();
        } catch (\Throwable) {
            $errors[] = 'The database connection failed. Check its host, name, user and password in cPanel.';
        }
    }

    if ($errors === []) {
        try {
            @set_time_limit(0);
            $private = $root.'/storage/app/private';
            if (! is_dir($private) && ! mkdir($private, 0700, true) && ! is_dir($private)) {
                throw new \RuntimeException('Private storage is not writable.');
            }
            $licencePath = $private.'/licence.json';
            $licenceTemporary = $licencePath.'.tmp';
            file_put_contents($licenceTemporary, json_encode($licence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
            chmod($licenceTemporary, 0600);
            rename($licenceTemporary, $licencePath);

            $settings = [
                'APP_NAME' => 'True-Doctor', 'APP_ENV' => 'production', 'APP_EDITION' => 'standalone',
                'APP_DEBUG' => 'false', 'APP_URL' => $values['app_url'],
                // The organisation's own clock, for every time the system shows or schedules.
                'APP_TIMEZONE' => $values['timezone'],
                'APP_PUBLIC_PATH' => __DIR__,
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'DB_CONNECTION' => 'mysql', 'DB_HOST' => $values['db_host'],
                'DB_PORT' => $values['db_port'], 'DB_DATABASE' => $databaseName,
                'DB_USERNAME' => $databaseUser, 'DB_PASSWORD' => $databasePassword,
                'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file',
                'SESSION_SECURE_COOKIE' => $secure ? 'true' : 'false',
                'SESSION_ENCRYPT' => 'true', 'QUEUE_CONNECTION' => 'database',
                'FILESYSTEM_DISK' => 'local', 'BROADCAST_CONNECTION' => 'log',
                'ONBOARDING_GATE' => 'true', 'SUBSCRIPTION_GATE' => 'false',
                'TELESCOPE_ENABLED' => 'false', 'DEMO_MODE' => 'false',
                'APP_UPDATE_SERVER' => 'https://true-doctor.online',
                // The sender's name on every email; Laravel's own default is "Example".
                'MAIL_FROM_NAME' => $values['hospital_name'],
            ];
            if ($values['mail_host'] !== '') {
                $settings += [
                    'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => $values['mail_host'],
                    'MAIL_PORT' => $values['mail_port'], 'MAIL_USERNAME' => $values['mail_user'],
                    'MAIL_PASSWORD' => (string) ($_POST['mail_password'] ?? ''),
                    'MAIL_SCHEME' => $values['mail_port'] === '465' ? 'smtps' : 'smtp',
                    'MAIL_FROM_ADDRESS' => $values['mail_from'],
                ];
            } else {
                $settings['MAIL_MAILER'] = 'log';
            }

            $details = [
                'hospital_name' => $values['hospital_name'],
                'owner_name' => $values['owner_name'],
                'owner_email' => $values['owner_email'],
                'owner_password' => (string) $_POST['owner_password'],
                'currency' => $values['currency'],
                'timezone' => $values['timezone'],
            ];
            $token = (new \App\Services\Install\InstallDraft)->save($draftPath, $settings['APP_KEY'], $details);
            (new \App\Services\Install\EnvironmentWriter)->write($environmentFile, $settings);
            $_SESSION['install_token'] = $token;
            $_SESSION['progress_completed'] = 0;
            $_SESSION['progress_total'] = count(glob($root.'/database/migrations/*.php') ?: []);
            header('Location: /install.php?step=1', true, 303);
            exit;
        } catch (\Throwable $e) {
            if (! is_file($environmentFile)) {
                @unlink($draftPath);
            }
            error_log('True-Doctor installation failed: '.get_class($e).': '.$e->getMessage());
            $errors[] = 'Setup could not begin. Check the application log or contact support before changing the database.';
        }
    }
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install True-Doctor</title><meta name="robots" content="noindex,nofollow">
<style>
  :root{font-family:Inter,Arial,sans-serif;color:#18232c;background:#f7f8f8;font-size:14px}
  *{box-sizing:border-box}body{margin:0}main{max-width:840px;margin:5vh auto;padding:24px}
  h1{font-size:25px;font-weight:500;letter-spacing:-.03em;margin:0 0 8px}h2{font-size:15px;font-weight:500;margin:0 0 14px}
  p{line-height:1.5;color:#59656e;margin:0 0 16px}.mark{font-size:12px;letter-spacing:.13em;color:#0b6e76;margin-bottom:12px}
  section{background:#fff;border:1px solid #dce2e4;padding:22px;margin:18px 0}label{display:block;font-size:12px;color:#33434a;margin:12px 0 5px}
  input{width:100%;border:1px solid #bfcbcf;border-radius:0;padding:10px 11px;font:inherit;background:#fff;color:inherit}
  input:focus{outline:2px solid #0b6e76;outline-offset:1px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}
  button,.button{display:inline-block;background:#075b65;border:1px solid #075b65;border-radius:0;color:white;text-decoration:none;padding:11px 17px;font:inherit;cursor:pointer}
  .bad{border-left:3px solid #a83d30;background:#fff3f1;padding:12px 14px;margin:12px 0}.checks{list-style:none;padding:0;margin:0;color:#47545b}.checks li{padding:5px 0}
  .checks .fail{color:#a83d30}.small{font-size:12px;color:#68757d}code{font-family:ui-monospace,monospace;font-size:12px;overflow-wrap:anywhere}
  @media(max-width:620px){main{padding:16px;margin:0}.grid{grid-template-columns:1fr}section{padding:17px}}
</style></head><body><main>
<div class="mark">TRUE-DOCTOR / STANDALONE</div>
<?php if ($installed): ?>
  <h1>Your installation is ready</h1>
  <?php if (! empty($_SESSION['mail_test'])): ?><div class="bad" role="status"><?= $escape($_SESSION['mail_test']) ?></div><?php endif; ?>
  <p>Your organisation and owner account are set up. Add the scheduler in cPanel so reminders, queued mail and overnight billing keep running.</p>
  <section><h2>One cron job</h2><p>In cPanel Cron Jobs, choose <b>Once Per Minute</b> and paste this command. <code>/usr/local/bin/php</code> is the command-line PHP on most cPanel hosts; if yours uses another path, your host's help pages give it. The system shows a warning to administrators until the scheduler has checked in.</p><code>cd <?= $escape($root) ?> &amp;&amp; /usr/local/bin/php artisan schedule:run &gt;/dev/null 2&gt;&amp;1</code></section>
  <a class="button" href="/admin/login">Go to sign in</a>
<?php elseif (is_file($draftPath)): ?>
  <h1>Preparing your system</h1>
  <p>The browser is applying the database changes in short, safe steps. Keep this tab open; if it closes, return here with the same browser to continue.</p>
  <?php foreach ($errors as $error): ?><div class="bad" role="alert"><?= $escape($error) ?></div><?php endforeach; ?>
  <section><h2>Database progress</h2>
    <p><span class="mono"><?= $escape((int) ($_SESSION['progress_completed'] ?? 0)) ?></span> of <span class="mono"><?= $escape((int) ($_SESSION['progress_total'] ?? 0)) ?></span> migrations complete.</p>
    <progress max="<?= $escape(max(1, (int) ($_SESSION['progress_total'] ?? 1))) ?>" value="<?= $escape((int) ($_SESSION['progress_completed'] ?? 0)) ?>" style="width:100%;height:12px"></progress>
    <form id="advance-form" method="post"><input type="hidden" name="_token" value="<?= $escape($_SESSION['csrf']) ?>"><button type="submit"><?= $errors === [] ? 'Continue setup' : 'Retry this step' ?></button></form>
  </section>
  <?php if ($errors === [] && $ready): ?><script>setTimeout(function(){document.getElementById('advance-form').requestSubmit()},350)</script><?php endif; ?>
<?php else: ?>
  <h1>Set up your organisation</h1>
  <p>Use the licence file beside your download. Create an empty MySQL database and user in cPanel first. Installation will prepare the system and the first owner account.</p>
  <?php foreach ($errors as $error): ?><div class="bad" role="alert"><?= $escape($error) ?></div><?php endforeach; ?>
  <section><h2>Server checks</h2><ul class="checks">
    <li class="<?= PHP_VERSION_ID >= 80200 ? '' : 'fail' ?>">PHP 8.2 or newer: <?= $escape(PHP_VERSION) ?></li>
    <li class="<?= $missingExtensions === [] ? '' : 'fail' ?>">Extensions: <?= $missingExtensions === [] ? 'ready' : $escape(implode(', ', $missingExtensions).' missing') ?></li>
    <li class="<?= is_writable($root) && is_writable($root.'/storage') && is_writable($root.'/bootstrap/cache') ? '' : 'fail' ?>">Application and storage folders: <?= is_writable($root) && is_writable($root.'/storage') && is_writable($root.'/bootstrap/cache') ? 'writable' : 'check permissions' ?></li>
    <li class="<?= $secure || $local ? '' : 'fail' ?>">Secure address: <?= $secure ? 'HTTPS ready' : ($local ? 'local test' : 'enable HTTPS first') ?></li>
  </ul></section>
  <form method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="_token" value="<?= $escape($_SESSION['csrf']) ?>">
    <section><h2>1 · Licence and site</h2>
      <label for="licence">licence.json</label><input id="licence" type="file" name="licence" accept=".json,application/json" required>
      <label for="app_url">This server’s exact address</label><input id="app_url" name="app_url" type="url" required value="<?= $escape($values['app_url']) ?>">
    </section>
    <section><h2>2 · MySQL database</h2><div class="grid">
      <div><label for="db_host">Host</label><input id="db_host" name="db_host" required value="<?= $escape($values['db_host']) ?>"></div>
      <div><label for="db_port">Port</label><input id="db_port" name="db_port" inputmode="numeric" required value="<?= $escape($values['db_port']) ?>"></div>
      <div><label for="db_name">Database name</label><input id="db_name" name="db_name" required></div>
      <div><label for="db_user">Database user</label><input id="db_user" name="db_user" required></div>
      <div><label for="db_password">Database password</label><input id="db_password" name="db_password" type="password"></div>
    </div></section>
    <section><h2>3 · Organisation and owner</h2><div class="grid">
      <div><label for="hospital_name">Organisation name</label><input id="hospital_name" name="hospital_name" required value="<?= $escape($values['hospital_name']) ?>"></div>
      <div><label for="currency">Currency code</label><input id="currency" name="currency" required maxlength="3" value="<?= $escape($values['currency']) ?>"></div>
      <div><label for="timezone">Time zone</label><input id="timezone" name="timezone" required value="<?= $escape($values['timezone']) ?>"></div>
      <div><label for="owner_name">Owner name</label><input id="owner_name" name="owner_name" required value="<?= $escape($values['owner_name']) ?>"></div>
      <div><label for="owner_email">Owner email</label><input id="owner_email" name="owner_email" type="email" required value="<?= $escape($values['owner_email']) ?>"></div>
      <div><label for="owner_password">Owner password (12+ characters)</label><input id="owner_password" name="owner_password" type="password" required minlength="12"></div>
    </div></section>
    <section><h2>4 · Email (optional during setup)</h2><p class="small">You can add SMTP now or configure it before inviting staff. Without it, messages are written to the server log.</p><div class="grid">
      <div><label for="mail_host">SMTP host</label><input id="mail_host" name="mail_host" value="<?= $escape($values['mail_host']) ?>"></div>
      <div><label for="mail_port">SMTP port</label><input id="mail_port" name="mail_port" inputmode="numeric" value="<?= $escape($values['mail_port']) ?>"></div>
      <div><label for="mail_user">SMTP user</label><input id="mail_user" name="mail_user" value="<?= $escape($values['mail_user']) ?>"></div>
      <div><label for="mail_password">SMTP password</label><input id="mail_password" name="mail_password" type="password"></div>
      <div><label for="mail_from">From address</label><input id="mail_from" name="mail_from" type="email" value="<?= $escape($values['mail_from']) ?>"></div>
    </div></section>
    <button type="submit" <?= $ready ? '' : 'disabled' ?>>Install True-Doctor</button>
    <p class="small" style="margin-top:12px">Keep this browser open while the database is prepared. Back up the generated .env file; its APP_KEY protects prepaid card lookups.</p>
  </form>
<?php endif; ?>
<?php if ($supportLink !== null || ! empty($support['email'] ?? null)): ?>
  <p class="small" style="margin-top:18px">Stuck?
    <?php if ($supportLink !== null): ?><a href="<?= $escape($supportLink) ?>" target="_blank" rel="noopener">WhatsApp <?= $escape((string) ($support['whatsapp_display'] ?? '')) ?></a><?php endif; ?>
    <?php if (! empty($support['phone'] ?? null)): ?> · call <?= $escape((string) $support['phone']) ?><?php endif; ?>
    <?php if (! empty($support['email'] ?? null)): ?> · <?= $escape((string) $support['email']) ?><?php endif; ?>
    <?php if (! empty($support['hours'] ?? null)): ?> · <?= $escape((string) $support['hours']) ?><?php endif; ?>
  </p>
<?php endif; ?>
</main></body></html>
