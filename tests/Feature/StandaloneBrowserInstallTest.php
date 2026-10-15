<?php

namespace Tests\Feature;

use App\Services\Install\StandaloneInstaller;
use App\Services\Licensing\Signer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StandaloneBrowserInstallTest extends TestCase
{
    public function test_short_browser_steps_install_every_migration_and_seed_the_first_enterprise(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'td-install-db-');
        $licence = tempnam(sys_get_temp_dir(), 'td-install-licence-');
        $marker = sys_get_temp_dir().'/td-install-complete-'.bin2hex(random_bytes(6));
        $previous = config('database.connections.sqlite.database');
        try {
            $pair = sodium_crypto_sign_keypair();
            config([
                'edition.name' => 'saas', 'edition.signing_key_id' => 'browser-test',
                'edition.signing_secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
                'edition.public_keys' => ['browser-test' => base64_encode(sodium_crypto_sign_publickey($pair))],
            ]);
            $payload = [
                'format' => 1, 'product' => 'standalone-hospital', 'id' => 'browser-proof',
                'customer' => 'Proof Buyer', 'domains' => ['localhost'],
                'updates_until' => '2099-12-31',
            ];
            file_put_contents($licence, json_encode(['licence' => $payload] + app(Signer::class)->sign($payload), JSON_THROW_ON_ERROR));
            config([
                'edition.name' => 'standalone', 'edition.licence_path' => $licence,
                'edition.installed_path' => $marker, 'app.url' => 'http://localhost',
                'database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
            ]);
            DB::purge('sqlite');
            $details = [
                'hospital_name' => 'Browser Hospital', 'owner_name' => 'Browser Owner',
                'owner_email' => 'owner@example.test', 'owner_password' => 'browser-test-password-123',
                'currency' => 'UGX', 'timezone' => 'Africa/Kampala',
            ];
            $installer = app(StandaloneInstaller::class);
            $first = $installer->advance($details, 5);
            $this->assertFalse($first['done']);
            $this->assertSame(5, $first['completed']);
            $steps = 1;
            do {
                $state = $installer->advance($details, 5);
                $steps++;
            } while (! $state['done'] && $steps < 30);
            $this->assertTrue($state['done']);
            $this->assertGreaterThan(15, $steps);
            $this->assertSame($state['total'], DB::table('migrations')->count());
            $this->assertSame(1, DB::table('hospitals')->count());
            $this->assertSame(1, DB::table('users')->count());
            // The owner is not asked to verify the address they installed with.
            $this->assertNotNull(DB::table('users')->value('email_verified_at'));
            $this->assertGreaterThan(0, DB::table('ledger_accounts')->count());
            $this->assertFileExists($marker);
        } finally {
            config(['database.connections.sqlite.database' => $previous]);
            DB::purge('sqlite');
            @unlink($database);
            @unlink($licence);
            @unlink($marker);
        }
    }

    /**
     * install.php runs BEFORE Laravel: nothing above the point where it boots
     * the application may need the container. It once loaded
     * config/edition.php, which calls storage_path(), and every real buyer's
     * licence was then reported invalid — while this suite, which runs with
     * Laravel already booted, passed.
     */
    public function test_the_installer_needs_nothing_from_laravel_before_it_boots_it(): void
    {
        // The whole file, not just the lines above the boot: the licence check
        // runs in a branch that never boots Laravel at all.
        $source = (string) file_get_contents(public_path('install.php'));

        foreach (['/config/', 'storage_path(', 'base_path(', ' config(', ' app(', ' env('] as $needsLaravel) {
            $this->assertStringNotContainsString($needsLaravel, $source, "install.php uses {$needsLaravel}, which needs a running Laravel application");
        }
    }

    /** Before setup there is no .env: the site sends visitors to the installer, never to a server error. */
    public function test_the_front_controller_sends_an_uninstalled_copy_to_the_installer(): void
    {
        $root = storage_path('framework/testing/docroot-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($root.'/true_doctor_app/vendor');
        File::ensureDirectoryExists($root.'/app');
        file_put_contents($root.'/true_doctor_app/vendor/autoload.php', '<?php echo "BOOTED"; exit;');
        copy(base_path('deploy/docroot-index.php'), $root.'/app/index.php');
        file_put_contents($root.'/app/install.php', '<?php');
        $run = fn (): string => (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/app/index.php').' 2>&1');

        try {
            $this->assertStringNotContainsString('BOOTED', $run());

            file_put_contents($root.'/true_doctor_app/.env', 'APP_KEY=');
            $this->assertStringContainsString('BOOTED', $run());
        } finally {
            File::deleteDirectory($root);
        }
    }

    /** Laravel's own default sender name is "Example"; an installed copy signs mail as the organisation. */
    public function test_the_installer_names_the_mail_sender_after_the_organisation(): void
    {
        $this->assertStringContainsString(
            "'MAIL_FROM_NAME' => \$values['hospital_name']",
            (string) file_get_contents(public_path('install.php')),
        );
    }

    /** The zone the installer asks for is the one the installation runs on. */
    public function test_the_installation_runs_on_the_time_zone_it_was_given(): void
    {
        $this->assertStringContainsString(
            "'APP_TIMEZONE' => \$values['timezone']",
            (string) file_get_contents(public_path('install.php')),
        );
        $this->assertStringContainsString("env('APP_TIMEZONE', 'UTC')", (string) file_get_contents(config_path('app.php')));
    }
}
