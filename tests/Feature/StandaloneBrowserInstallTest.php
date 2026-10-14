<?php

namespace Tests\Feature;

use App\Services\Install\StandaloneInstaller;
use App\Services\Licensing\Signer;
use Illuminate\Support\Facades\DB;
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
}
