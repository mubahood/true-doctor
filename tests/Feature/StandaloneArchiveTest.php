<?php

namespace Tests\Feature;

use App\Services\Licensing\Signer;
use App\Services\Releases\StandaloneArchive;
use App\Support\Licensing\Verifier;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class StandaloneArchiveTest extends TestCase
{
    private string $staged;

    private string $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staged = storage_path('framework/testing/release-'.bin2hex(random_bytes(5)));
        $this->archive = $this->staged.'.zip';
        File::ensureDirectoryExists($this->staged);
        foreach (['app', 'bootstrap', 'config', 'database', 'resources/standalone', 'routes', 'vendor', 'public/build', 'deploy'] as $directory) {
            File::ensureDirectoryExists($this->staged.'/'.$directory);
        }
        foreach (['artisan', 'composer.json', 'composer.lock', 'INSTALL.md', 'vendor/autoload.php', 'public/build/manifest.json', 'public/install.php', 'deploy/docroot-index.php', 'deploy/docroot-htaccess'] as $file) {
            file_put_contents($this->staged.'/'.$file, 'test fixture');
        }
        $pair = sodium_crypto_sign_keypair();
        config()->set('edition.name', 'saas');
        config()->set('edition.signing_key_id', 'fixture');
        config()->set('edition.signing_secret', base64_encode(sodium_crypto_sign_secretkey($pair)));
        config()->set('edition.public_keys', []);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->staged);
        @unlink($this->archive);
        parent::tearDown();
    }

    public function test_package_is_signed_and_contains_a_shared_hosting_layout(): void
    {
        $builder = new StandaloneArchive;
        $built = $builder->build($this->staged, $this->archive, '1.2.3', str_repeat('a', 40), new Signer);
        $this->assertSame(hash_file('sha256', $this->archive), $built['sha256']);

        $zip = new ZipArchive;
        $zip->open($this->archive, ZipArchive::RDONLY);
        $keys = json_decode((string) $zip->getFromName('true_doctor_app/RELEASE_KEYS.json'), true);
        $this->assertNotFalse($zip->locateName('true_doctor_app/INSTALL.md'));
        $this->assertNotFalse($zip->locateName('web_root_files/index.php'));
        $this->assertNotFalse($zip->locateName('web_root_files/.htaccess'));
        $this->assertFalse($zip->locateName('true_doctor_app/.env'));
        $zip->close();

        $manifest = $builder->verify($this->archive, new Verifier($keys));
        $this->assertSame('1.2.3', $manifest['version']);
        $extracted = $this->staged.'/extracted';
        File::ensureDirectoryExists($extracted);
        $zip->open($this->archive, ZipArchive::RDONLY);
        $zip->extractTo($extracted);
        $zip->close();
        $this->assertSame('1.2.3', $builder->verifyExtracted($extracted.'/true_doctor_app', $extracted.'/web_root_files', new Verifier($keys))['version']);
    }

    /**
     * No file extracts world-writable — an in-memory entry defaults to 0666,
     * which made the signed file list writable by anybody on a shared host.
     * Only the directories PHP itself must write are left open.
     */
    public function test_no_file_extracts_world_writable(): void
    {
        (new StandaloneArchive)->build($this->staged, $this->archive, '1.2.3', str_repeat('a', 40), new Signer);

        $zip = new ZipArchive;
        $zip->open($this->archive, ZipArchive::RDONLY);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            $name = (string) $zip->getNameIndex($i);
            $mode = ($attributes >> 16) & 0777;
            $this->assertSame(ZipArchive::OPSYS_UNIX, $system, $name);
            $this->assertSame(str_ends_with($name, '/') ? 0777 : 0644, $mode, sprintf('%s extracts as %o', $name, $mode));
        }
        $zip->close();
    }

    public function test_secret_file_is_rejected_before_packaging(): void
    {
        file_put_contents($this->staged.'/app/.env', 'DB_PASSWORD=must-never-ship');

        $this->expectException(\RuntimeException::class);
        (new StandaloneArchive)->build($this->staged, $this->archive, '1.2.3', str_repeat('a', 40), new Signer);
    }

    public function test_tampering_or_traversal_is_rejected(): void
    {
        $builder = new StandaloneArchive;
        $builder->build($this->staged, $this->archive, '1.2.3', str_repeat('a', 40), new Signer);
        $keys = json_decode((string) file_get_contents($this->staged.'/RELEASE_KEYS.json'), true);
        $zip = new ZipArchive;
        $zip->open($this->archive);
        $zip->addFromString('web_root_files/install.php', 'tampered');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $builder->verify($this->archive, new Verifier($keys));
    }
}
