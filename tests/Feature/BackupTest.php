<?php

namespace Tests\Feature;

use App\Services\Backups\MakesArchive;
use App\Services\Backups\OffsiteBackup;
use App\Services\Backups\RestoreDrill;
use App\Support\Backups\BackupCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

/** The online service's encrypted, off-site backups and the restore drill. */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = storage_path('framework/testing/backups-'.uniqid());
        File::ensureDirectoryExists($this->work);
        config(['backup.encryption_key' => BackupCipher::newKey()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        File::deleteDirectory(storage_path('app/private/backups/offsite'));
        parent::tearDown();
    }

    /** An archive shaped like InstallationBackups makes, with a dump naming every live table (or not). */
    private function fakeArchive(array $skipTables = [], bool $complete = true): void
    {
        $dump = $this->work.'/database.sql.gz';
        $gz = gzopen($dump, 'wb');
        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if (! in_array($table, $skipTables, true)) {
                gzwrite($gz, "CREATE TABLE `{$table}` (id int);\n-- td:statement\nINSERT INTO `{$table}` (`id`) VALUES (1);\n-- td:statement\n");
            }
        }
        if ($complete) {
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- td:statement\n");
        }
        gzclose($gz);

        $zipPath = $this->work.'/archive.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($dump, 'database.sql.gz');
        $zip->addFromString('RESTORE.txt', 'how');
        $zip->close();

        $this->app->instance(MakesArchive::class, new class($zipPath) implements MakesArchive
        {
            public function __construct(private string $zipPath) {}

            public function create(): array
            {
                $copy = $this->zipPath.'.'.uniqid().'.zip';
                copy($this->zipPath, $copy);

                return ['path' => $copy, 'size' => (int) filesize($copy)];
            }
        });
    }

    public function test_the_cipher_round_trips_and_refuses_a_wrong_key_or_a_changed_file(): void
    {
        $plain = $this->work.'/plain.bin';
        file_put_contents($plain, random_bytes(3 * 1024 * 1024 + 17)); // more than one chunk
        $key = (string) BackupCipher::key();

        BackupCipher::encrypt($plain, $this->work.'/sealed', $key);
        BackupCipher::decrypt($this->work.'/sealed', $this->work.'/opened', $key);
        $this->assertSame(sha1_file($plain), sha1_file($this->work.'/opened'));
        $this->assertStringNotContainsString(substr((string) file_get_contents($plain), 100, 64), (string) file_get_contents($this->work.'/sealed'));

        $wrong = base64_decode(BackupCipher::newKey());
        $this->expectException(\RuntimeException::class);
        BackupCipher::decrypt($this->work.'/sealed', $this->work.'/nope', $wrong);
    }

    public function test_a_cut_short_backup_is_refused(): void
    {
        $plain = $this->work.'/plain.bin';
        file_put_contents($plain, random_bytes(2 * 1024 * 1024));
        $key = (string) BackupCipher::key();
        BackupCipher::encrypt($plain, $this->work.'/sealed', $key);
        file_put_contents($this->work.'/short', substr((string) file_get_contents($this->work.'/sealed'), 0, 1024 * 1024));

        $this->expectExceptionMessage('incomplete');
        BackupCipher::decrypt($this->work.'/short', $this->work.'/opened', $key);
    }

    public function test_without_a_key_nothing_is_made(): void
    {
        config(['backup.encryption_key' => null]);
        $this->fakeArchive();

        $this->expectExceptionMessage('BACKUP_ENCRYPTION_KEY');
        app(OffsiteBackup::class)->run();
    }

    public function test_without_a_store_the_sealed_copy_stays_here_and_the_plain_one_does_not(): void
    {
        $this->fakeArchive();

        $made = app(OffsiteBackup::class)->run();

        $this->assertFalse($made['offsite']);
        $this->assertNotNull(app(OffsiteBackup::class)->newestLocal());
        $this->assertSame([], glob($this->work.'/archive.zip.*.zip'), 'the unencrypted archive is deleted');
        $this->assertFalse(OffsiteBackup::last()['offsite']);
    }

    public function test_with_a_store_it_uploads_signed_keeps_a_monthly_copy_and_prunes_to_30_daily(): void
    {
        config(['backup.s3' => ['endpoint' => 'https://s3.example.test', 'region' => 'auto', 'bucket' => 'td', 'key' => 'AK', 'secret' => 'SK', 'prefix' => 'true-doctor']]);
        $this->fakeArchive();
        $daily = collect(range(1, 31))->map(fn ($i) => sprintf('<Contents><Key>true-doctor/daily/true-doctor-202609%02d-013000.tdbak</Key><Size>10</Size><LastModified>x</LastModified></Contents>', $i))->implode('');
        Http::fake([
            's3.example.test/td/?*prefix=true-doctor%2Fdaily%2F*' => Http::response("<ListBucketResult><IsTruncated>false</IsTruncated>{$daily}</ListBucketResult>"),
            's3.example.test/td/?*prefix=true-doctor%2Fmonthly%2F*' => Http::response('<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>'),
            '*' => Http::response('', 200),
        ]);

        $made = app(OffsiteBackup::class)->run();

        $this->assertTrue($made['offsite']);
        $this->assertTrue($made['monthly']);
        $puts = Http::recorded(fn (Request $r) => $r->method() === 'PUT')->values();
        $this->assertCount(2, $puts, 'the daily copy and this month\'s copy');
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AK/', $puts[0][0]->header('Authorization')[0]);
        $this->assertStringStartsWith('TDBAK1', $puts[0][0]->body(), 'only the encrypted file leaves the server');
        $deletes = Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->values();
        $this->assertCount(1, $deletes, '31 listed, 30 kept');
        $this->assertStringContainsString('20260901', $deletes[0][0]->url(), 'the oldest goes');
    }

    public function test_the_drill_reads_a_good_backup_and_passes(): void
    {
        $this->fakeArchive();
        app(OffsiteBackup::class)->run();

        $result = app(RestoreDrill::class)->run();

        $this->assertTrue($result['ok'], implode(' ', $result['problems']));
        $this->assertSame('read', $result['mode']);
        $this->assertTrue(RestoreDrill::last()['ok']);
    }

    public function test_the_drill_fails_a_backup_missing_a_table_or_cut_short(): void
    {
        $this->fakeArchive(skipTables: ['patients']);
        app(OffsiteBackup::class)->run();
        $result = app(RestoreDrill::class)->run();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('patients', implode(' ', $result['problems']));

        $this->travel(2)->seconds();
        $this->fakeArchive(complete: false);
        app(OffsiteBackup::class)->run();
        $this->assertFalse(app(RestoreDrill::class)->run()['ok']);
    }

    public function test_the_drill_never_restores_over_the_live_database(): void
    {
        config(['backup.drill' => ['host' => config('database.connections.sqlite.host'), 'port' => null, 'database' => config('database.connections.sqlite.database'), 'username' => 'x', 'password' => 'y']]);
        config(['database.connections.sqlite.host' => null]);
        $this->fakeArchive();
        app(OffsiteBackup::class)->run();

        $result = app(RestoreDrill::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('live database', implode(' ', $result['problems']));
    }

    public function test_a_new_key_is_printed_on_request(): void
    {
        $this->artisan('td:backup --new-key')->expectsOutputToContain('BACKUP_ENCRYPTION_KEY=')->assertSuccessful();
    }
}
