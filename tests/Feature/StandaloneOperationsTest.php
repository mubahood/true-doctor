<?php

namespace Tests\Feature;

use App\Livewire\Super\Licences\Index as Licences;
use App\Livewire\Super\Products\Index as Products;
use App\Livewire\Super\Releases\Index as Releases;
use App\Livewire\System\Index as SystemPage;
use App\Models\Hospital;
use App\Models\StandaloneLicence;
use App\Models\StandaloneProduct;
use App\Models\StandaloneRelease;
use App\Notifications\StandaloneDownloadReady;
use App\Services\Gateway\GatewayCharge;
use App\Services\Gateway\GatewayVerification;
use App\Services\Gateway\PesapalGateway;
use App\Services\Install\DatabaseBackup;
use App\Services\Install\InstallationBackups;
use App\Services\Install\SystemState;
use App\Services\StandaloneSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Running the standalone business (docs/proposals/standalone-edition.md §6–§9):
 * the super admin's products, licences and releases; renewals and address
 * changes from the buyer's side; the update feed an installed copy reads; and
 * the owner's Licence & updates page with its backups.
 */
class StandaloneOperationsTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private string $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        config()->set('edition.name', 'saas');
        config()->set('edition.signing_key_id', 'ops-test');
        config()->set('edition.signing_secret', base64_encode(sodium_crypto_sign_secretkey($pair)));
        config()->set('edition.public_keys', ['ops-test' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        Notification::fake();

        $this->archive = storage_path('framework/testing/ops-'.bin2hex(random_bytes(4)).'.zip');
        File::ensureDirectoryExists(dirname($this->archive));
        file_put_contents($this->archive, 'release fixture');
    }

    protected function tearDown(): void
    {
        @unlink($this->archive);
        File::deleteDirectory(storage_path('app/private/system'));
        parent::tearDown();
    }

    /** A standalone installation that has finished installing. */
    private function asInstalledStandalone(): void
    {
        config()->set('edition.name', 'standalone');
        $marker = storage_path('framework/testing/installed-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($marker, json_encode(['edition' => 'standalone', 'version' => '1.0.0']));
        config()->set('edition.installed_path', $marker);
        $this->beforeApplicationDestroyed(fn () => @unlink($marker));
    }

    private function product(array $attributes = []): StandaloneProduct
    {
        return StandaloneProduct::create($attributes + [
            'name' => 'Hospital', 'slug' => 'hospital-'.bin2hex(random_bytes(3)), 'price_ugx' => '1500000.00',
            'renewal_price_ugx' => '300000.00', 'updates_months' => 12, 'is_active' => true,
        ]);
    }

    private function release(string $version = '1.0.0', array $attributes = []): StandaloneRelease
    {
        return StandaloneRelease::create($attributes + [
            'version' => $version, 'status' => 'published', 'published_at' => now(),
            'archive_path' => $this->archive, 'sha256' => hash_file('sha256', $this->archive),
        ]);
    }

    private function licence(StandaloneProduct $product, array $attributes = []): StandaloneLicence
    {
        return StandaloneLicence::create($attributes + [
            'product_id' => $product->id, 'customer_name' => 'St Mary', 'customer_email' => 'owner@stmary.test',
            'domains' => ['hms.stmary.test'], 'updates_until' => now()->addYear()->toDateString(),
            'status' => 'active', 'source' => 'online',
        ]);
    }

    // ── The update feed an installed copy reads ──────────────────────────

    public function test_the_feed_offers_the_newest_entitled_release_with_a_signed_download(): void
    {
        $licence = $this->licence($this->product());
        $this->release('1.0.0', ['published_at' => now()->subMonth()]);
        $newest = $this->release('1.1.0');

        $answer = $this->getJson('/standalone/updates?licence='.$licence->id.'&version=1.0.0')->assertOk()->json('latest');

        $this->assertSame('1.1.0', $answer['version']);
        $this->assertSame($newest->sha256, $answer['sha256']);
        $this->get($answer['download_url'])->assertOk();
        $this->assertSame(1, DB::table('standalone_downloads')->count());

        // Not signed: refused.
        $this->get('/standalone/updates/'.$newest->id.'/download?licence='.$licence->id)->assertForbidden();
    }

    public function test_the_feed_says_nothing_to_a_revoked_or_unknown_licence(): void
    {
        $licence = $this->licence($this->product(), ['status' => 'revoked']);
        $this->release();

        $this->getJson('/standalone/updates?licence='.$licence->id)->assertOk()->assertJson(['latest' => null]);
        $this->getJson('/standalone/updates?licence=00000000-0000-0000-0000-000000000000')->assertOk()->assertJson(['latest' => null]);
    }

    public function test_a_release_after_the_entitlement_is_not_offered(): void
    {
        $licence = $this->licence($this->product(), ['updates_until' => now()->subDay()->toDateString()]);
        $this->release('1.0.0', ['published_at' => now()->subMonths(2)]);
        $this->release('1.1.0');

        $this->assertSame('1.0.0', $this->getJson('/standalone/updates?licence='.$licence->id)->json('latest.version'));
    }

    // ── Renewals and addresses ───────────────────────────────────────────

    public function test_a_paid_renewal_extends_the_same_licence_once(): void
    {
        $this->release();
        $licence = $this->licence($this->product(), ['updates_until' => now()->addMonth()->toDateString()]);
        $gateway = \Mockery::mock(PesapalGateway::class);
        $gateway->shouldReceive('initialize')->once()->andReturnUsing(fn (array $d) => new GatewayCharge(true, $d['tx_ref'], 'https://pay.test'));
        app()->instance(PesapalGateway::class, $gateway);
        $sales = app(StandaloneSalesService::class);

        [$order] = $sales->startRenewal($licence);
        $this->assertSame('renewal', $order->kind);
        $this->assertSame('300000.00', (string) $order->amount_ugx);

        $paid = new GatewayVerification(true, $order->reference, 'trk-1', '300000.00', 'UGX');
        $this->assertSame('settled', $sales->settleVerified($paid));
        $this->assertSame('already_settled', $sales->settleVerified($paid));

        $this->assertSame(1, StandaloneLicence::count());
        $this->assertSame(now()->addMonth()->addMonthsNoOverflow(12)->toDateString(), $licence->fresh()->updates_until->toDateString());
        Notification::assertSentOnDemand(StandaloneDownloadReady::class, fn ($n) => $n->reason === 'renewed');
    }

    public function test_a_product_without_a_renewal_price_is_renewed_by_contact(): void
    {
        $this->release();
        $licence = $this->licence($this->product(['renewal_price_ugx' => null]));

        $this->expectException(\DomainException::class);
        app(StandaloneSalesService::class)->startRenewal($licence);
    }

    public function test_a_buyer_moves_their_licence_once_a_month_and_staff_any_time(): void
    {
        $licence = $this->licence($this->product());
        $sales = app(StandaloneSalesService::class);

        $sales->changeDomain($licence, 'HMS.NewPlace.test');
        $this->assertSame('hms.newplace.test', $licence->fresh()->domain());
        $this->assertSame(['hms.newplace.test'], $sales->document($licence->fresh())['licence']['domains']);

        try {
            $sales->changeDomain($licence->fresh(), 'again.example.test');
            $this->fail('A second change inside a month must be refused.');
        } catch (\DomainException) {
        }

        $sales->changeDomain($licence->fresh(), 'again.example.test', byStaff: true);
        $this->assertSame('again.example.test', $licence->fresh()->domain());

        $this->expectException(\DomainException::class);
        $sales->changeDomain($licence->fresh(), 'https://bad/path', byStaff: true);
    }

    public function test_the_portal_shows_the_download_the_licence_and_the_guide_together(): void
    {
        $licence = $this->licence($this->product());
        $this->release('1.0.0');

        $this->get(\Illuminate\Support\Facades\URL::temporarySignedRoute('standalone.portal.claim', now()->addHour(), ['licence' => $licence->id]))
            ->assertRedirect(route('standalone.portal'));

        $this->get('/my-licence')->assertOk()
            ->assertSee('True-Doctor 1.0.0')
            ->assertSee('Download licence.json')
            ->assertSee('Install True-Doctor on your own server')   // the guide, on the same page
            ->assertSee('Renew updates');
        $this->get('/my-licence/guide')->assertOk()->assertSee('Run the installer');

        $this->post('/my-licence/domain', ['domain' => 'moved.example.test'])->assertRedirect();
        $this->assertSame('moved.example.test', $licence->fresh()->domain());
    }

    public function test_asking_for_a_link_never_reveals_who_has_a_licence(): void
    {
        $this->licence($this->product());

        $this->post('/my-licence/link', ['email' => 'owner@stmary.test'])->assertSessionHas('status');
        $this->post('/my-licence/link', ['email' => 'nobody@example.test'])->assertSessionHas('status');

        Notification::assertSentOnDemandTimes(StandaloneDownloadReady::class, 1);
    }

    // ── The super admin's controls ──────────────────────────────────────

    public function test_the_super_admin_sets_a_price_issues_a_licence_and_the_buyer_is_emailed(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(Products::class)
            ->set('name', 'Clinic')->set('price', '3500000')->set('renewalPrice', '700000')
            ->set('updatesMonths', 12)->set('maxStaff', 15)->set('isActive', true)
            ->call('save')->assertHasNoErrors();
        $product = StandaloneProduct::firstOrFail();
        $this->assertSame('clinic', $product->slug);
        $this->assertSame('700000.00', (string) $product->renewal_price_ugx);

        Livewire::test(Licences::class)
            ->call('openIssue')
            ->set('productId', $product->id)->set('customerName', 'Kampala Eye Centre')
            ->set('customerEmail', 'admin@eye.test')->set('domain', 'hms.eye.test')->set('reference', 'BANK-001')
            ->call('issue')->assertHasNoErrors();

        $licence = StandaloneLicence::firstOrFail();
        $this->assertSame('offline', $licence->source);
        $this->assertSame(15, $licence->limits['max_staff']);
        Notification::assertSentOnDemand(StandaloneDownloadReady::class, fn ($n, $c, AnonymousNotifiable $to) => $to->routes['mail'] === 'admin@eye.test' && $n->reason === 'issued');

        Livewire::test(Licences::class)->call('changeDomain', $licence->id, 'new.eye.test');
        $this->assertSame('new.eye.test', $licence->fresh()->domain());
    }

    public function test_the_release_page_says_what_staging_still_needs(): void
    {
        $this->actingAsSuperAdmin();
        config()->set('edition.test_database', ['host' => null, 'user' => null]);

        Livewire::test(Releases::class)
            ->assertSee('Clean-install proof database')
            ->call('stage')
            ->assertHasErrors('version');
        $this->assertSame(0, StandaloneRelease::count());
    }

    public function test_only_a_proven_release_can_be_published(): void
    {
        $this->actingAsSuperAdmin();
        $release = StandaloneRelease::create(['version' => '1.0.0', 'status' => 'failed']);

        Livewire::test(Releases::class)->call('publish', $release->id)->assertDispatched('toast');
        $this->assertSame('failed', $release->fresh()->status);
    }

    // ── The standalone owner's page ──────────────────────────────────────

    public function test_the_owner_sees_licence_and_updates_and_can_queue_a_backup(): void
    {
        $this->asInstalledStandalone();
        $hospital = Hospital::factory()->create();
        $this->actingAsRole('hospital_admin', $hospital);
        config(['support.whatsapp' => '256700111222']);

        Livewire::test(SystemPage::class)
            ->assertSee('Licence & updates')
            ->assertSee('Missing or invalid')
            ->assertSee('Trouble with an update, a backup or the licence? WhatsApp us')
            ->assertSee('https://wa.me/256700111222', false)
            ->call('backupNow')
            ->assertDispatched('toast');

        $this->assertSame('backup', app(SystemState::class)->pending()['action']);
        $before = array_column(app(InstallationBackups::class)->list(), 'name');

        // The scheduler picks it up and makes the backup.
        $made = storage_path('framework/testing/backup-db');
        app()->instance(DatabaseBackup::class, new class($made) extends DatabaseBackup
        {
            public function __construct(private string $made) {}

            public function dump(string $path): string
            {
                File::ensureDirectoryExists(dirname($path));
                file_put_contents($path.'.sql.gz', 'fixture');

                return $path.'.sql.gz';
            }
        });
        $this->artisan('standalone:system-requests')->assertSuccessful();

        $this->assertSame('succeeded', app(SystemState::class)->current()['status']);
        $made = array_values(array_diff(array_column(app(InstallationBackups::class)->list(), 'name'), $before));
        $this->assertCount(1, $made);
        try {
            $this->get(route('admin.system.backup', $made[0]))->assertOk();
            $this->get('/admin/system/backups/..%2F..%2F.env')->assertNotFound();
        } finally {
            @unlink(storage_path('app/private/backups/'.$made[0]));
        }
    }

    public function test_the_owner_reaches_it_while_setup_is_still_outstanding(): void
    {
        config()->set('onboarding.gate_enabled', true);
        $this->asInstalledStandalone();
        $this->actingAsRole('hospital_admin', Hospital::factory()->create());

        $this->get('/admin/visits')->assertRedirect(route('admin.onboarding'));
        $this->get('/admin/system')->assertOk();
    }

    public function test_staff_without_settings_access_cannot_open_it_and_saas_has_no_such_page(): void
    {
        $hospital = Hospital::factory()->create();
        $this->actingAsRole('nurse', $hospital);
        $this->asInstalledStandalone();
        $this->get('/admin/system')->assertForbidden();

        config()->set('edition.name', 'saas');
        config()->set('edition.installed_path', storage_path('framework/testing/none.json'));
        $this->get('/admin/system')->assertNotFound();
    }

    public function test_an_uploaded_file_that_is_not_a_genuine_release_is_refused(): void
    {
        $this->asInstalledStandalone();
        $this->actingAsRole('hospital_admin', Hospital::factory()->create());

        $fake = \Illuminate\Http\UploadedFile::fake()->createWithContent('update.zip', 'not a zip');
        $this->post(route('admin.system.upload'), ['package' => $fake])
            ->assertRedirect(route('admin.system.index'))->assertSessionHas('error');
        $this->assertNull(app(SystemState::class)->pending());
    }

    // ── Keys, health, backups ────────────────────────────────────────────

    public function test_keygen_makes_a_working_pair_and_refuses_on_a_customer_installation(): void
    {
        $this->artisan('standalone:keygen', ['--id' => 'test-1'])
            ->expectsOutputToContain('RELEASE_SIGNING_KEY_ID=test-1')
            ->assertSuccessful();

        config()->set('edition.name', 'standalone');
        $this->artisan('standalone:keygen')->assertFailed();
    }

    public function test_health_passes_on_a_migrated_seeded_database(): void
    {
        $this->seedRbac();
        $this->artisan('standalone:health')->assertSuccessful();
    }

    public function test_a_sqlite_database_survives_a_backup_and_restore(): void
    {
        $file = storage_path('framework/testing/backup-'.bin2hex(random_bytes(4)).'.sqlite');
        touch($file);
        config()->set('database.connections.backup_test', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]);
        $default = config('database.default');
        config()->set('database.default', 'backup_test');

        try {
            DB::statement('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
            DB::table('notes')->insert(['body' => 'kept']);

            $dump = app(DatabaseBackup::class)->dump(storage_path('framework/testing/dump-'.bin2hex(random_bytes(3))));
            DB::table('notes')->delete();
            app(DatabaseBackup::class)->restore($dump);

            $this->assertSame('kept', DB::table('notes')->value('body'));
            @unlink($dump);
        } finally {
            DB::disconnect('backup_test');
            config()->set('database.default', $default);
            @unlink($file);
        }
    }
}
