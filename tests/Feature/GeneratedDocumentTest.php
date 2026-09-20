<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use App\Support\DocumentBrand;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Everything this system prints (docs/documents.md).
 *
 * Two rules, and every document is held to both:
 *
 *  1. IT OPENS, IT DOES NOT DOWNLOAD. A document is read before it is kept, so
 *     it is served inline and the link opens a tab. SpaNavigationHtmlTest holds
 *     the links to it; this holds the responses.
 *  2. IT SAYS WHO PRINTED IT. One letterhead, resolved by DocumentBrand and
 *     rendered by <x-pdf.document>, so a hospital that sets its logo and phone
 *     number sets them on all of them at once.
 */
class GeneratedDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create(['name' => 'Kisenyi Referral Hospital']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();
        $this->actingAs($this->admin);
    }

    /** A hospital that has filled its letterhead in. */
    private function withLetterhead(): void
    {
        $this->hospital->forceFill([
            'address' => 'Plot 14, Nakivubo Road, Kampala',
            'settings' => array_merge($this->hospital->settings ?? [], [
                'profile' => [
                    'phone' => '+256 700 000 000',
                    'email' => 'accounts@kisenyi.test',
                    'website' => 'kisenyi.test',
                    'registration' => 'Licence UMDPC/4471',
                ],
                'billing' => array_merge($this->hospital->settings['billing'] ?? [], [
                    'invoice_footer' => 'Payable within 30 days.',
                ]),
            ]),
        ])->save();

        app()->forgetInstance(\App\Support\HospitalSettings::class);
        app()->forgetInstance(DocumentBrand::class);
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function patient(): Patient
    {
        return Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    private function invoice(): \App\Models\Invoice
    {
        $visit = Visit::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => $this->patient()->id,
        ]);
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '40000.00']);
        app(BillingService::class)->orderService($visit, $service->id, 2);

        return app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);
    }

    /** @return list<array{0:string,1:string}> every generated document, with a label */
    private function documents(): array
    {
        $invoice = $this->invoice();

        $payment = app(BillingService::class)
            ->recordPayment($invoice, PaymentMethod::Cash, '10000.00', [], $this->admin->id);

        // Built through the services, as everything else in the system builds
        // them — there are no factories for these, and a hand-rolled row would
        // be a different shape from the one a real document is printed from.
        $visit = Visit::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => $this->patient()->id,
        ]);

        $test = \App\Models\LabTest::factory()->create(['hospital_id' => $this->hospital->id]);
        $lab = app(\App\Services\LabService::class)
            ->order($visit, [$test->id], 'Query malaria.', $this->admin->id);

        $study = \App\Models\RadiologyStudy::factory()->create(['hospital_id' => $this->hospital->id]);
        $radiology = app(\App\Services\RadiologyService::class)
            ->order($visit, [$study->id], 'Persistent cough.', $this->admin->id);
        app(\App\Services\RadiologyService::class)
            ->recordReport($radiology, 'Clear lung fields.', 'No acute abnormality.', $this->admin->id);

        $ward = \App\Models\Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = \App\Models\Bed::factory()->create([
            'hospital_id' => $this->hospital->id,
            'ward_id' => $ward->id,
            'status' => \App\Enums\BedStatus::Available,
        ]);
        $admission = app(\App\Services\AdmissionService::class)->admit($this->patient(), $bed, [
            'admitted_at' => now()->subDays(2)->toDateTimeString(),
            'admitting_doctor_id' => $this->admin->id,
            'reason' => 'Severe dehydration.',
            'title' => 'Admit for IV fluids',
        ], $this->admin->id);

        $insurer = InsuranceProvider::create(['name' => 'Jubilee Health', 'is_active' => true]);

        return [
            ['invoice', route('admin.invoices.pdf', $invoice)],
            ['receipt', route('admin.payments.receipt', $payment)],
            ['lab report', route('admin.lab-orders.pdf', $lab)],
            ['radiology report', route('admin.radiology-orders.pdf', $radiology)],
            ['discharge summary', route('admin.admissions.summary', $admission)],
            ['patient ID card', route('admin.patients.id-card', $this->patient())],
            ['insurance statement', route('admin.insurance-providers.usage', $insurer)],
            ['period report', route('admin.reports.pdf', [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
            ])],
        ];
    }

    // ── They open ────────────────────────────────────────────────────────

    /**
     * Every one of them renders, and every one of them is served INLINE.
     *
     * `attachment` is what sends a browser straight to its downloads folder for
     * a document somebody only wanted to glance at.
     */
    public function test_every_document_renders_and_opens_rather_than_downloading(): void
    {
        $this->withLetterhead();

        foreach ($this->documents() as [$label, $url]) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'), "the {$label} is not a PDF");

            $disposition = (string) $response->headers->get('content-disposition');
            $this->assertStringStartsWith('inline', $disposition, "the {$label} downloads instead of opening");
            $this->assertStringContainsString('.pdf', $disposition, "the {$label} has no filename to save under");
        }
    }

    /** A filename still matters — "Save as" has to offer something readable. */
    public function test_a_document_is_named_after_what_it_is(): void
    {
        $invoice = $this->invoice();

        $disposition = (string) $this->get(route('admin.invoices.pdf', $invoice))
            ->headers->get('content-disposition');

        $this->assertStringContainsString($invoice->invoice_no.'.pdf', $disposition);
    }

    // ── They carry the letterhead ────────────────────────────────────────

    /**
     * The hospital's own details reach every document.
     *
     * Asserted on the rendered HTML rather than the PDF bytes: DomPDF's output
     * is compressed, so searching it for a phone number proves nothing either
     * way. What matters is that the template put it there.
     */
    public function test_every_document_carries_the_hospitals_own_details(): void
    {
        $this->withLetterhead();

        $brand = app(DocumentBrand::class)->resolve();

        $this->assertSame('Kisenyi Referral Hospital', $brand['name']);
        $this->assertSame('+256 700 000 000', $brand['phone']);
        $this->assertSame('accounts@kisenyi.test', $brand['email']);
        $this->assertSame('Licence UMDPC/4471', $brand['registration']);
        $this->assertSame('Payable within 30 days.', $brand['footer']);

        $invoice = $this->invoice();

        $html = view('pdf.invoice', ['invoice' => $invoice, 'hospital' => $this->hospital])->render();

        foreach (['Kisenyi Referral Hospital', 'Plot 14, Nakivubo Road, Kampala',
            '+256 700 000 000', 'accounts@kisenyi.test', 'Licence UMDPC/4471',
            'Payable within 30 days.'] as $expected) {
            $this->assertStringContainsString($expected, $html, "the invoice does not carry “{$expected}”");
        }
    }

    /** A hospital with nothing filled in still gets a document, not a crash. */
    public function test_a_bare_hospital_still_prints(): void
    {
        $brand = app(DocumentBrand::class)->resolve();

        $this->assertNotSame('', $brand['name']);
        $this->assertNull($brand['phone']);
        $this->assertNull($brand['logo']);

        $this->get(route('admin.invoices.pdf', $this->invoice()))->assertOk();
    }

    // ── The logo ─────────────────────────────────────────────────────────

    public function test_a_logo_is_embedded_rather_than_linked(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/mark.png', $this->onePixelPng());

        $this->hospital->forceFill(['logo' => 'logos/mark.png'])->save();
        app()->forgetInstance(DocumentBrand::class);
        app()->forgetInstance(\App\Support\HospitalSettings::class);
        app(CurrentHospital::class)->set($this->hospital->id);

        $logo = app(DocumentBrand::class)->resolve()['logo'];

        // DomPDF runs with enable_remote off and a chroot on the project root,
        // so anything but a data URI would silently draw nothing.
        $this->assertNotNull($logo);
        $this->assertStringStartsWith('data:image/png;base64,', $logo);

        $html = view('pdf.invoice', ['invoice' => $this->invoice(), 'hospital' => $this->hospital])->render();
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    /** A logo file that has gone must not take the invoice with it. */
    public function test_a_missing_logo_file_is_ignored(): void
    {
        Storage::fake('public');
        $this->hospital->forceFill(['logo' => 'logos/gone.png'])->save();
        app()->forgetInstance(DocumentBrand::class);
        app()->forgetInstance(\App\Support\HospitalSettings::class);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertNull(app(DocumentBrand::class)->resolve()['logo']);
        $this->get(route('admin.invoices.pdf', $this->invoice()))->assertOk();
    }

    /** An SVG or a PDF named as a logo is not something to draw into a page. */
    public function test_only_a_real_raster_image_is_drawn(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->hospital->forceFill(['logo' => 'logos/mark.svg'])->save();
        app()->forgetInstance(DocumentBrand::class);
        app()->forgetInstance(\App\Support\HospitalSettings::class);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertNull(app(DocumentBrand::class)->resolve()['logo']);
    }

    // ── Setting it ───────────────────────────────────────────────────────

    public function test_the_letterhead_page_saves_the_details_and_the_logo(): void
    {
        Storage::fake('public');

        \Livewire\Livewire::test(\App\Livewire\Settings\Hospital::class)
            ->set('name', 'Kisenyi Referral Hospital')
            ->set('address', 'Plot 14, Nakivubo Road')
            ->set('phone', '+256 700 111 222')
            ->set('email', 'billing@kisenyi.test')
            ->set('registration', 'Licence 99')
            ->set('footer', 'Thank you.')
            ->set('logo', UploadedFile::fake()->image('mark.png', 400, 160))
            ->call('save')
            ->assertHasNoErrors();

        $hospital = $this->hospital->fresh();

        $this->assertSame('Kisenyi Referral Hospital', $hospital->name);
        $this->assertSame('Plot 14, Nakivubo Road', $hospital->address);
        $this->assertSame('+256 700 111 222', $hospital->settings['profile']['phone']);
        $this->assertSame('Licence 99', $hospital->settings['profile']['registration']);
        $this->assertNotNull($hospital->logo);
        Storage::disk('public')->assertExists($hospital->logo);

        // The footer is the billing setting, not a second copy of it.
        $this->assertSame('Thank you.', $hospital->settings['billing']['invoice_footer']);
    }

    /** Replacing a logo does not leave the old one on the disk forever. */
    public function test_a_replaced_logo_is_cleaned_up(): void
    {
        Storage::fake('public');

        $page = \Livewire\Livewire::test(\App\Livewire\Settings\Hospital::class)
            ->set('name', 'Kisenyi')
            ->set('logo', UploadedFile::fake()->image('first.png', 300, 120))
            ->call('save');

        $first = $this->hospital->fresh()->logo;

        $page->set('logo', UploadedFile::fake()->image('second.png', 300, 120))->call('save');

        $second = $this->hospital->fresh()->logo;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_removing_the_logo_takes_it_off_every_document(): void
    {
        Storage::fake('public');

        \Livewire\Livewire::test(\App\Livewire\Settings\Hospital::class)
            ->set('name', 'Kisenyi')
            ->set('logo', UploadedFile::fake()->image('mark.png', 300, 120))
            ->call('save')
            ->call('removeLogo');

        $this->assertNull($this->hospital->fresh()->logo);
    }

    public function test_a_role_without_settings_rights_cannot_change_the_letterhead(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $this->actingAs($nurse);

        \Livewire\Livewire::test(\App\Livewire\Settings\Hospital::class)->assertForbidden();
    }

    private function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}
