<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Your practice, Hospital letterhead and Billing settings over the API. */
class AdminSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create(['name' => 'Alpha Clinic', 'address' => 'Plot 1']);
    }

    private function as(string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $this->h->id, 'role' => $role]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_every_settings_page_needs_manage_settings(): void
    {
        $this->as('nurse');
        $this->getJson('/api/v1/admin/settings/practice')->assertStatus(403);
        $this->putJson('/api/v1/admin/settings/practice', ['sector' => 'dental_clinic'])->assertStatus(403);
        $this->postJson('/api/v1/admin/settings/practice/apply')->assertStatus(403);
        $this->getJson('/api/v1/admin/settings/hospital')->assertStatus(403);
        $this->postJson('/api/v1/admin/settings/hospital', ['name' => 'X'])->assertStatus(403);
        $this->getJson('/api/v1/admin/settings/billing')->assertStatus(403);
        $this->putJson('/api/v1/admin/settings/billing', ['currency_code' => 'USD'])->assertStatus(403);
        $this->assertSame('Alpha Clinic', $this->h->fresh()->name);
    }

    // ── Your practice ───────────────────────────────────────────

    public function test_the_practice_is_read_chosen_and_the_template_applied(): void
    {
        $this->as('hospital_admin');

        $this->getJson('/api/v1/admin/settings/practice')->assertOk()
            ->assertJsonPath('data.chosen', false)->assertJsonPath('data.sector', 'general_hospital')
            ->assertJsonStructure(['data' => ['modules', 'addable_packs', 'counts', 'catalogue' => ['sectors', 'packs', 'modules', 'parts']]]);

        $this->putJson('/api/v1/admin/settings/practice', ['sector' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors('sector', 'errors');
        $this->postJson('/api/v1/admin/settings/practice/apply')->assertStatus(422);

        $res = $this->putJson('/api/v1/admin/settings/practice', ['sector' => 'dental_clinic', 'packs' => ['imaging']])->assertOk()
            ->assertJsonPath('data.chosen', true)->assertJsonPath('data.sector', 'dental_clinic')->assertJsonPath('data.packs', ['imaging'])
            ->assertJsonPath('data.modules_customised', false);
        $this->assertNotContains('inpatient', $res->json('data.modules'));
        $this->assertSame('dental_clinic', $this->h->fresh()->settings['practice']['sector']);
        $this->assertGreaterThan(0, $res->json('data.preview.departments.new'));

        $this->postJson('/api/v1/admin/settings/practice/apply', ['parts' => ['departments']])->assertOk()
            ->assertJsonPath('data.created.departments', fn ($n) => $n > 0);
        $this->assertGreaterThan(0, Department::withoutGlobalScopes()->where('hospital_id', $this->h->id)->count());

        // Applying again adds nothing: only what is missing is written.
        $this->postJson('/api/v1/admin/settings/practice/apply', ['parts' => ['departments']])->assertOk()->assertJsonPath('data.total', 0);

        // The admin's own module choice sticks.
        $this->putJson('/api/v1/admin/settings/practice', ['sector' => 'dental_clinic', 'modules' => ['pharmacy']])->assertOk()
            ->assertJsonPath('data.modules', ['pharmacy'])->assertJsonPath('data.modules_customised', true);
    }

    // ── Hospital letterhead ─────────────────────────────────────

    public function test_the_letterhead_is_saved_partially_and_validated_like_the_web(): void
    {
        $this->as('hospital_admin');

        $this->postJson('/api/v1/admin/settings/hospital', [
            'phone' => '+256 700 000000', 'email' => 'info@alpha.example', 'footer' => 'Thank you', 'tagline' => 'Care first', 'brand_colour' => '#1C4795',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Alpha Clinic')->assertJsonPath('data.address', 'Plot 1')
            ->assertJsonPath('data.phone', '+256 700 000000')->assertJsonPath('data.footer', 'Thank you')
            ->assertJsonPath('data.tagline', 'Care first')->assertJsonPath('data.brand_colour', '#1C4795');

        $fresh = $this->h->fresh();
        $this->assertSame('+256 700 000000', $fresh->settings['profile']['phone']);
        $this->assertSame('Thank you', $fresh->settings['billing']['invoice_footer']);

        // Sending only one field leaves the rest alone.
        $this->postJson('/api/v1/admin/settings/hospital', ['tagline' => 'New line'])->assertOk()
            ->assertJsonPath('data.phone', '+256 700 000000')->assertJsonPath('data.brand_colour', '#1C4795')->assertJsonPath('data.tagline', 'New line');

        // Too light for white text on buttons; not a colour at all; a bad email; no name.
        $this->postJson('/api/v1/admin/settings/hospital', ['brand_colour' => '#FFFF00'])->assertStatus(422)->assertJsonValidationErrors('brand_colour', 'errors');
        $this->postJson('/api/v1/admin/settings/hospital', ['brand_colour' => 'blue'])->assertStatus(422);
        $this->postJson('/api/v1/admin/settings/hospital', ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email', 'errors');
        $this->postJson('/api/v1/admin/settings/hospital', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name', 'errors');

        // Clearing a field is possible by sending it empty.
        $this->postJson('/api/v1/admin/settings/hospital', ['tagline' => ''])->assertOk()->assertJsonPath('data.tagline', null);
    }

    public function test_the_logo_and_wide_logo_upload_and_are_removed(): void
    {
        Storage::fake('public');
        $this->as('hospital_admin');

        $res = $this->post('/api/v1/admin/settings/hospital', [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'wordmark' => UploadedFile::fake()->image('wide.png', 600, 200),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotNull($res->json('data.logo_url'));
        $this->assertNotNull($res->json('data.wordmark_url'));

        $logo = $this->h->fresh()->logo;
        Storage::disk('public')->assertExists($logo);

        // A file that is not a picture is refused with the web's rule.
        $this->post('/api/v1/admin/settings/hospital', ['logo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('logo', 'errors');

        $this->deleteJson('/api/v1/admin/settings/hospital/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        Storage::disk('public')->assertMissing($logo);
        $this->deleteJson('/api/v1/admin/settings/hospital/wordmark')->assertOk()->assertJsonPath('data.wordmark_url', null);
    }

    // ── Billing ─────────────────────────────────────────────────

    public function test_billing_is_read_and_saved_with_the_web_validation(): void
    {
        $this->as('hospital_admin');

        $this->getJson('/api/v1/admin/settings/billing')->assertOk()
            ->assertJsonStructure(['data' => ['settings' => ['currency_code', 'decimals', 'invoice_prefix', 'tax_enabled'], 'preview', 'invoice_example', 'recorded', 'currencies']]);

        $this->putJson('/api/v1/admin/settings/billing', [
            'currency_code' => 'usd', 'currency_symbol' => '$', 'currency_position' => 'before', 'decimals' => 2,
            'thousands_separator' => ',', 'decimal_separator' => '.', 'tax_enabled' => true, 'tax_label' => 'VAT', 'tax_rate' => '18',
            'consultation_fee' => '25', 'invoice_prefix' => 'TD',
        ])->assertOk()
            ->assertJsonPath('data.settings.currency_code', 'USD')->assertJsonPath('data.settings.tax_enabled', true)
            ->assertJsonPath('data.preview', '$1,234,567.89')->assertJsonPath('data.currency_relabelled', false);

        $fresh = $this->h->fresh();
        $this->assertSame('USD', $fresh->currency);
        $this->assertSame('TD', $fresh->settings['billing']['invoice_prefix']);

        // The letterhead footer shares this key and survives a billing save.
        $this->postJson('/api/v1/admin/settings/hospital', ['footer' => 'Thanks'])->assertOk();
        $this->putJson('/api/v1/admin/settings/billing', ['invoice_prefix' => 'ZZ'])->assertOk()->assertJsonPath('data.settings.invoice_footer', 'Thanks')->assertJsonPath('data.settings.currency_code', 'USD');

        // Validation: the two separators must differ; ranges; prefix characters.
        $this->putJson('/api/v1/admin/settings/billing', ['decimal_separator' => ',', 'thousands_separator' => ','])->assertStatus(422)->assertJsonValidationErrors('decimal_separator', 'errors');
        $this->putJson('/api/v1/admin/settings/billing', ['tax_rate' => '150'])->assertStatus(422)->assertJsonValidationErrors('tax_rate', 'errors');
        $this->putJson('/api/v1/admin/settings/billing', ['decimals' => 5])->assertStatus(422);
        $this->putJson('/api/v1/admin/settings/billing', ['invoice_prefix' => 'no spaces!'])->assertStatus(422);
        $this->assertSame('ZZ', $this->h->fresh()->settings['billing']['invoice_prefix']);
    }

    public function test_changing_the_currency_over_recorded_invoices_says_it_relabels_not_converts(): void
    {
        $this->as('hospital_admin');
        $this->h->forceFill(['currency' => 'UGX'])->save();
        app(\App\Support\CurrentHospital::class)->set($this->h->id);
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $patient->id]);
        Invoice::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'visit_id' => $visit->id,
            'patient_id' => $patient->id, 'invoice_no' => 'INV-'.$visit->id, 'currency' => 'UGX',
            'subtotal' => '30000', 'tax_total' => '0', 'discount' => '0', 'total' => '30000', 'amount_paid' => '0', 'balance' => '30000',
            'status' => 'issued', 'issued_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/settings/billing')->assertOk()->assertJsonPath('data.recorded.invoices', 1)->assertJsonPath('data.recorded.currency', 'UGX');
        $this->putJson('/api/v1/admin/settings/billing', ['currency_code' => 'USD'])->assertOk()->assertJsonPath('data.currency_relabelled', true);
    }

    public function test_another_hospitals_settings_are_never_touched(): void
    {
        $other = Hospital::factory()->create(['name' => 'Beta Clinic']);
        $this->as('hospital_admin');

        $this->postJson('/api/v1/admin/settings/hospital', ['name' => 'Alpha Renamed'])->assertOk();
        $this->putJson('/api/v1/admin/settings/billing', ['invoice_prefix' => 'AL'])->assertOk();
        $this->putJson('/api/v1/admin/settings/practice', ['sector' => 'eye_clinic'])->assertOk();

        $this->assertSame('Beta Clinic', $other->fresh()->name);
        $this->assertArrayNotHasKey('billing', $other->fresh()->settings ?? []);
        $this->assertArrayNotHasKey('practice', $other->fresh()->settings ?? []);
    }
}
