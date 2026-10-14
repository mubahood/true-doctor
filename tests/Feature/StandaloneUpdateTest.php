<?php

namespace Tests\Feature;

use App\Services\Install\ArtisanRunner;
use App\Services\Install\DatabaseBackup;
use App\Services\Install\StandaloneUpdater;
use App\Services\Install\SystemState;
use App\Services\Licensing\Signer;
use App\Services\Releases\StandaloneArchive;
use App\Support\Licensing\Canonical;
use App\Support\Licensing\InstalledLicence;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Updating an installed standalone copy (docs/proposals/standalone-edition.md §9):
 * only a genuine, newer, entitled package is accepted; a good one replaces the
 * code by its manifest and runs the migrations on the new code; a failure puts
 * the previous code and database back.
 *
 * Every write goes to temporary folders and a fake runner, never into the
 * application running the tests.
 */
class StandaloneUpdateTest extends TestCase
{
    private string $root;

    private string $appRoot;

    private string $web;

    private string $secret;

    private string $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/update-'.bin2hex(random_bytes(5)));
        $this->appRoot = $this->root.'/true_doctor_app';
        $this->web = $this->root.'/public_html';
        File::ensureDirectoryExists($this->appRoot.'/storage/app/private');
        File::ensureDirectoryExists($this->web);

        $pair = sodium_crypto_sign_keypair();
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));

        // The installed 1.0.0: one file the update changes, one it drops.
        $this->installed('1.0.0', ['app/Marker.php' => 'old', 'app/Gone.php' => 'gone']);
        $this->licence('2099-12-31');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory(storage_path('app/private/system'));
        parent::tearDown();
    }

    public function test_a_genuine_newer_package_replaces_the_code_and_migrates_on_the_new_code(): void
    {
        $zip = $this->package('1.1.0', ['app/Marker.php' => 'new', 'app/Added.php' => 'added']);
        $runner = new FakeRunner;
        $backup = new FakeBackup;

        $result = $this->updater($backup, $runner)->apply($zip);

        $this->assertSame('1.1.0', $result['to']);
        $this->assertSame('new', file_get_contents($this->appRoot.'/app/Marker.php'));
        $this->assertSame('added', file_get_contents($this->appRoot.'/app/Added.php'));
        $this->assertFileDoesNotExist($this->appRoot.'/app/Gone.php');
        $this->assertSame('1.1.0', json_decode((string) file_get_contents($this->root.'/PACKAGE.json'), true)['manifest']['version']);
        $this->assertSame([['down'], ['migrate', '--force'], ['db:seed', '--class=Database\\Seeders\\StandaloneReferenceSeeder', '--force'], ['finance:backfill', '--no-interaction'], ['standalone:health'], ['up']], $runner->calls);
        $this->assertTrue($backup->dumped);
        $this->assertFalse($backup->restored);
        $this->assertSame('1.1.0', json_decode((string) file_get_contents(config('edition.installed_path')), true)['version']);
        $this->assertSame('succeeded', app(SystemState::class)->current()['status']);
    }

    public function test_a_failed_migration_puts_the_code_and_database_back(): void
    {
        $zip = $this->package('1.1.0', ['app/Marker.php' => 'new', 'app/Added.php' => 'added']);
        $runner = new FakeRunner(failOn: 'migrate');
        $backup = new FakeBackup;

        try {
            $this->updater($backup, $runner)->apply($zip);
            $this->fail('The update should have stopped.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('old', file_get_contents($this->appRoot.'/app/Marker.php'));
        $this->assertSame('gone', file_get_contents($this->appRoot.'/app/Gone.php'));
        $this->assertFileDoesNotExist($this->appRoot.'/app/Added.php');
        $this->assertSame('1.0.0', json_decode((string) file_get_contents($this->root.'/PACKAGE.json'), true)['manifest']['version']);
        $this->assertTrue($backup->restored);
        $this->assertSame(['up'], end($runner->calls));
        $this->assertSame('failed', app(SystemState::class)->current()['status']);
    }

    public function test_a_tampered_package_is_refused_before_anything_changes(): void
    {
        $zip = $this->package('1.1.0', ['app/Marker.php' => 'new']);
        $archive = new ZipArchive;
        $archive->open($zip);
        $archive->addFromString('true_doctor_app/app/Marker.php', 'evil');
        $archive->close();

        $this->expectException(\DomainException::class);
        try {
            $this->updater(new FakeBackup, new FakeRunner)->inspect($zip);
        } finally {
            $this->assertSame('old', file_get_contents($this->appRoot.'/app/Marker.php'));
        }
    }

    public function test_a_package_signed_by_another_key_is_refused(): void
    {
        $zip = $this->package('1.1.0', ['app/Marker.php' => 'new']);
        $other = sodium_crypto_sign_keypair();
        config()->set('edition.public_keys', ['fixture' => base64_encode(sodium_crypto_sign_publickey($other))]);

        $this->expectException(\DomainException::class);
        $this->updater(new FakeBackup, new FakeRunner)->inspect($zip);
    }

    public function test_a_release_made_after_the_update_entitlement_is_refused(): void
    {
        $this->licence('2020-01-01');
        $zip = $this->package('1.1.0', ['app/Marker.php' => 'new']);

        $this->expectExceptionMessage('after your update entitlement ended');
        $this->updater(new FakeBackup, new FakeRunner)->inspect($zip);
    }

    public function test_an_update_runs_only_from_the_command_line_side_of_a_request(): void
    {
        $state = app(SystemState::class);
        $state->request(['action' => 'backup']);

        $this->expectException(\DomainException::class);
        $state->request(['action' => 'backup']);   // one thing at a time
    }

    public function test_a_waiting_request_is_claimed_exactly_once(): void
    {
        $state = app(SystemState::class);
        $state->request(['action' => 'backup', 'by' => 1]);

        $this->assertSame('backup', $state->claim()['action']);
        $this->assertNull($state->claim());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function updater(FakeBackup $backup, FakeRunner $runner): StandaloneUpdater
    {
        config()->set('edition.name', 'standalone');

        return new StandaloneUpdater($backup, app(SystemState::class), $runner, $this->appRoot, $this->web);
    }

    /** A signed package with these app files (plus the minimum every package has). @param array<string,string> $files */
    private function package(string $version, array $files): string
    {
        config()->set('edition.name', 'saas');
        config()->set('edition.signing_key_id', 'fixture');
        config()->set('edition.signing_secret', $this->secret);
        config()->set('edition.public_keys', []);

        $stage = $this->root.'/stage-'.$version;
        foreach (['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'vendor', 'public/build', 'deploy'] as $dir) {
            File::ensureDirectoryExists($stage.'/'.$dir);
        }
        foreach (['artisan', 'composer.json', 'composer.lock', 'INSTALL.md', 'vendor/autoload.php', 'public/build/manifest.json', 'public/install.php', 'deploy/docroot-index.php', 'deploy/docroot-htaccess'] as $file) {
            file_put_contents($stage.'/'.$file, 'fixture');
        }
        foreach ($files as $path => $contents) {
            file_put_contents($stage.'/'.$path, $contents);
        }

        $zip = $this->root.'/true-doctor-standalone-'.$version.'.zip';
        (new StandaloneArchive)->build($stage, $zip, $version, str_repeat('b', 40), new Signer);

        config()->set('edition.public_keys', ['fixture' => $this->public]);
        config()->set('edition.name', 'standalone');

        return $zip;
    }

    /** Lay out an installed release and its manifest. @param array<string,string> $files */
    private function installed(string $version, array $files): void
    {
        $manifest = ['format' => 1, 'product' => 'standalone-hospital', 'version' => $version, 'files' => []];
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($this->appRoot.'/'.$path));
            file_put_contents($this->appRoot.'/'.$path, $contents);
            $manifest['files']['true_doctor_app/'.$path] = ['sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
        }
        file_put_contents($this->root.'/PACKAGE.json', json_encode(['manifest' => $manifest]));

        config()->set('edition.installed_path', $this->appRoot.'/storage/app/private/installed.json');
        file_put_contents(config('edition.installed_path'), json_encode(['edition' => 'standalone', 'version' => $version]));
    }

    private function licence(string $updatesUntil): void
    {
        $payload = ['format' => 1, 'product' => 'standalone-hospital', 'id' => 'lic-test', 'customer' => 'Test Clinic', 'domains' => ['localhost'], 'updates_until' => $updatesUntil, 'limits' => []];
        $signature = base64_encode(sodium_crypto_sign_detached(Canonical::json($payload), (string) base64_decode($this->secret)));
        $path = $this->appRoot.'/storage/app/private/licence.json';
        file_put_contents($path, json_encode(['licence' => $payload, 'key_id' => 'fixture', 'signature' => $signature]));

        config()->set('edition.licence_path', $path);
        config()->set('edition.public_keys', ['fixture' => $this->public]);
        app()->forgetInstance(InstalledLicence::class);
    }
}

/** Records what the updater asked artisan to do, and never does it. */
class FakeRunner extends ArtisanRunner
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(private readonly ?string $failOn = null) {}

    public function down(): void
    {
        $this->calls[] = ['down'];
    }

    public function up(): void
    {
        $this->calls[] = ['up'];
    }

    public function fresh(string $appRoot, array $arguments): void
    {
        $this->calls[] = $arguments;
        if ($this->failOn !== null && $arguments[0] === $this->failOn) {
            throw new \RuntimeException("`{$this->failOn}` failed: fixture failure");
        }
    }
}

/** A database backup that only remembers being asked. */
class FakeBackup extends DatabaseBackup
{
    public bool $dumped = false;

    public bool $restored = false;

    public function dump(string $path): string
    {
        $this->dumped = true;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path.'.sql.gz', 'fixture');

        return $path.'.sql.gz';
    }

    public function restore(string $path): void
    {
        $this->restored = true;
    }
}
