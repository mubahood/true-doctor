<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use App\Services\Install\StandaloneInstaller;
use App\Services\Licensing\Signer;
use App\Support\CurrentHospital;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use App\Support\Licensing\Verifier;
use App\Support\PlanLimit;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandaloneLicenceTest extends TestCase
{
    use RefreshDatabase;

    private ?string $licenceFile = null;

    private ?string $installationMarker = null;

    protected function tearDown(): void
    {
        if ($this->licenceFile !== null && is_file($this->licenceFile)) {
            unlink($this->licenceFile);
        }
        if ($this->installationMarker !== null && is_file($this->installationMarker)) {
            unlink($this->installationMarker);
        }

        parent::tearDown();
    }

    public function test_signed_licence_verifies_offline_and_preserves_entitlement_after_update_expiry(): void
    {
        $pair = sodium_crypto_sign_keypair();
        config([
            'edition.name' => Edition::SAAS,
            'edition.signing_key_id' => 'test-a',
            'edition.signing_secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'edition.public_keys' => ['test-a' => base64_encode(sodium_crypto_sign_publickey($pair))],
        ]);

        $payload = [
            'product' => 'standalone-hospital', 'format' => 1,
            'id' => 'TD-TEST', 'customer' => 'Sample Hospital',
            'limits' => ['max_staff' => 3, 'max_patients' => 1, 'max_beds' => 1], 'domains' => ['hms.example.test'],
            'updates_until' => '2020-01-01',
        ];
        $signature = app(Signer::class)->sign($payload);
        $document = ['licence' => $payload] + $signature;

        $this->assertTrue(app(Verifier::class)->verifiesDocument($document));
        $document['licence']['customer'] = 'Different Hospital';
        $this->assertFalse(app(Verifier::class)->verifiesDocument($document));
        $document['licence']['customer'] = 'Sample Hospital';

        $this->licenceFile = tempnam(sys_get_temp_dir(), 'td-licence-');
        file_put_contents($this->licenceFile, json_encode($document, JSON_THROW_ON_ERROR));
        config(['edition.name' => Edition::STANDALONE, 'edition.licence_path' => $this->licenceFile]);

        $licence = app(InstalledLicence::class);
        $this->assertTrue($licence->isValid());
        $this->assertSame('Sample Hospital', $licence->customer());
        $this->assertSame(3, $licence->limit('max_staff'));
        $this->assertTrue($licence->matchesHost('HMS.EXAMPLE.TEST'));
        $this->assertFalse($licence->matchesHost('wrong.example.test'));
        $this->assertTrue($licence->updatesUntil()->isPast());
        $hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($hospital->id);
        $this->assertNull(app(PlanLimit::class)->limitFor('patients'));
        $this->assertNull(app(PlanLimit::class)->limitFor('beds'));
    }

    public function test_missing_licence_does_not_block_patients_visits_or_invoices(): void
    {
        config([
            'edition.name' => Edition::STANDALONE,
            'edition.licence_path' => sys_get_temp_dir().'/td-no-such-licence-file.json',
            'tenancy.enforce_subscription' => true,
        ]);
        $this->installationMarker = tempnam(sys_get_temp_dir(), 'td-installed-');
        file_put_contents($this->installationMarker, '{"edition":"standalone"}');
        config(['edition.installed_path' => $this->installationMarker]);

        $this->seed(RbacSeeder::class);
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']);
        $this->actingAs($user);
        app(CurrentHospital::class)->set($hospital->id);

        $this->assertFalse(app(InstalledLicence::class)->isValid());
        $this->assertNull(app(PlanLimit::class)->limitFor('staff'));
        $this->get('/admin/patients')->assertOk();
        $this->get('/admin/visits')->assertOk();
        $this->get('/admin/invoices')->assertOk();
    }

    public function test_installer_creates_one_owner_and_a_chart_from_a_valid_licence(): void
    {
        $pair = sodium_crypto_sign_keypair();
        config([
            'edition.name' => Edition::SAAS,
            'edition.signing_key_id' => 'installer-test',
            'edition.signing_secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'edition.public_keys' => ['installer-test' => base64_encode(sodium_crypto_sign_publickey($pair))],
        ]);
        $payload = [
            'format' => 1, 'product' => 'standalone-hospital', 'id' => 'fixture-install',
            'customer' => 'Buyer', 'domains' => ['localhost'], 'updates_until' => '2099-12-31',
        ];
        $this->licenceFile = tempnam(sys_get_temp_dir(), 'td-install-licence-');
        file_put_contents($this->licenceFile, json_encode(['licence' => $payload] + app(Signer::class)->sign($payload), JSON_THROW_ON_ERROR));
        $this->installationMarker = sys_get_temp_dir().'/td-install-marker-'.bin2hex(random_bytes(6));
        config([
            'edition.name' => Edition::STANDALONE,
            'edition.licence_path' => $this->licenceFile,
            'edition.installed_path' => $this->installationMarker,
            'app.url' => 'http://localhost',
        ]);
        $owner = app(StandaloneInstaller::class)->install([
            'hospital_name' => 'Buyer Clinic', 'owner_name' => 'Owner Example',
            'owner_email' => 'owner@example.test', 'owner_password' => 'long-test-password-123',
            'currency' => 'UGX', 'timezone' => 'Africa/Kampala',
        ]);
        $this->assertSame('hospital_admin', $owner->role);
        $this->assertSame(1, Hospital::count());
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('ledger_accounts')->count() > 0);
        $this->assertFileExists($this->installationMarker);
        try {
            \Illuminate\Support\Facades\DB::table('hospitals')->insert([
                'name' => 'Raw second hospital', 'slug' => 'raw-second-hospital',
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('A raw insert created a second enterprise.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, Hospital::count());
        }
        try {
            \Illuminate\Support\Facades\DB::table('hospitals')->where('id', $owner->hospital_id)->delete();
            $this->fail('A raw delete removed the installation identity.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, Hospital::count());
        }
    }
}
