<?php

namespace App\Services\Releases;

use App\Services\Install\EnvironmentWriter;
use App\Services\Licensing\Signer;
use App\Support\Licensing\Verifier;
use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use ZipArchive;

/** Proves the extracted buyer artifact works with an empty MySQL database. */
final class CleanInstallProof
{
    /** @param callable(string):void $progress */
    public function run(string $archive, callable $progress): void
    {
        $settings = config('edition.test_database');
        if (! is_array($settings) || ! is_string($settings['host'] ?? null) || $settings['host'] === ''
            || ! is_string($settings['user'] ?? null) || $settings['user'] === '') {
            throw new \RuntimeException('Configure an isolated MySQL release-test account before staging.');
        }
        $host = $settings['host'];
        $port = (string) ($settings['port'] ?? '3306');
        $user = $settings['user'];
        $password = (string) ($settings['password'] ?? '');
        $dedicated = is_string($settings['database'] ?? null) && $settings['database'] !== '' ? $settings['database'] : null;
        $database = $dedicated ?? 'td_release_'.bin2hex(random_bytes(7));
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
            throw new \RuntimeException('The release-test database name is not valid.');
        }
        $this->assertNotTheApplicationDatabase($host, $port, $database);
        $root = storage_path('app/private/release-proofs/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($root, 0700);

        // Either a throwaway database this account creates and drops, or —
        // on cPanel, where accounts cannot CREATE DATABASE over SQL — one
        // database kept for nothing but this, emptied before and after.
        $created = false;
        $pdo = null;
        try {
            $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($dedicated !== null) {
                $progress('Emptying the dedicated MySQL proof database');
                $this->emptyDatabase($pdo, $database);
            } else {
                $progress('Creating an empty, isolated MySQL proof database');
                $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $created = true;
            }

            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
                throw new \RuntimeException('The staged archive cannot be opened for proof.');
            }
            try {
                // The stage step already verified signature, paths and hashes;
                // check again immediately before extraction.
                $keys = json_decode((string) $zip->getFromName('true_doctor_app/RELEASE_KEYS.json'), true);
                if (! is_array($keys)) {
                    throw new \RuntimeException('The archive has no trusted public keys.');
                }
                (new StandaloneArchive)->verify($archive, new Verifier($keys));
                if (! $zip->extractTo($root)) {
                    throw new \RuntimeException('The release could not be extracted for proof.');
                }
            } finally {
                $zip->close();
            }

            $app = $root.'/true_doctor_app';
            $payload = [
                'format' => 1, 'product' => 'standalone-hospital',
                'id' => 'release-proof-'.$database, 'customer' => 'Release proof',
                'domains' => ['localhost'], 'updates_until' => '2099-12-31',
            ];
            $licence = ['licence' => $payload] + app(Signer::class)->sign($payload);
            file_put_contents($app.'/storage/app/private/licence.json', json_encode($licence, JSON_THROW_ON_ERROR));
            (new EnvironmentWriter)->write($app.'/.env', [
                'APP_NAME' => 'True-Doctor proof', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                'APP_EDITION' => 'standalone', 'APP_URL' => 'http://localhost',
                'APP_PUBLIC_PATH' => $root.'/web_root_files',
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
                'DB_DATABASE' => $database, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $password,
                'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'log', 'SUBSCRIPTION_GATE' => 'false',
            ]);
            $config = $root.'/install-settings.json';
            file_put_contents($config, json_encode([
                'hospital_name' => 'Release proof hospital', 'owner_name' => 'Proof Owner',
                'owner_email' => 'proof@example.test', 'owner_password' => bin2hex(random_bytes(20)),
                'currency' => 'UGX', 'timezone' => 'Africa/Kampala',
            ], JSON_THROW_ON_ERROR));
            chmod($config, 0600);
            $progress('Running the packaged installer and all migrations');
            $process = new Process([PHP_BINARY, 'artisan', 'standalone:install', '--config='.$config], $app, [
                'APP_EDITION' => 'standalone', 'APP_ENV' => 'testing',
            ]);
            $process->setTimeout(600);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Packaged clean install failed: '.mb_substr(trim($process->getErrorOutput().' '.$process->getOutput()), 0, 1000));
            }

            foreach (['hospitals' => 1, 'users' => 1, 'districts' => 1, 'roles' => 1, 'permissions' => 1, 'ledger_accounts' => 1] as $table => $minimum) {
                if ((int) $pdo->query("SELECT COUNT(*) FROM `{$database}`.`{$table}`")->fetchColumn() < $minimum) {
                    throw new \RuntimeException("The packaged install did not populate {$table}.");
                }
            }
            if (! is_file($app.'/storage/app/private/installed.json') || ! is_file($root.'/web_root_files/index.php')) {
                throw new \RuntimeException('The packaged installer or web-root layout is incomplete.');
            }
            $progress('Packaged clean install passed');
        } finally {
            try {
                if ($created && $pdo !== null) {
                    $pdo->exec("DROP DATABASE `{$database}`");
                } elseif ($dedicated !== null && $pdo !== null) {
                    $this->emptyDatabase($pdo, $database);
                }
            } finally {
                File::deleteDirectory($root);
            }
        }
    }

    /** Drop every table and view in the proof database, and nothing else anywhere. */
    private function emptyDatabase(PDO $pdo, string $database): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $statement = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
            $statement->execute([$database]);
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$table, $type]) {
                $name = str_replace('`', '``', (string) $table);
                $pdo->exec(($type === 'VIEW' ? 'DROP VIEW' : 'DROP TABLE')." IF EXISTS `{$database}`.`{$name}`");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Emptying the wrong database would be the worst thing this system could
     * do to itself, so the proof refuses outright to point at the one the
     * application is running on.
     */
    private function assertNotTheApplicationDatabase(string $host, string $port, string $database): void
    {
        $app = config('database.connections.'.config('database.default'));
        if (is_array($app) && ($app['driver'] ?? null) === 'mysql'
            && strcasecmp((string) ($app['database'] ?? ''), $database) === 0
            && in_array(strtolower((string) ($app['host'] ?? '')), [strtolower($host), 'localhost', '127.0.0.1'], true)
            && (string) ($app['port'] ?? '3306') === $port) {
            throw new \RuntimeException('The release-test database must not be the application database.');
        }
    }
}
