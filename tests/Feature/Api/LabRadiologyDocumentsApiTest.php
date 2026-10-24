<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use App\Models\RadiologyStudy;
use App\Models\User;
use App\Models\Visit;
use App\Services\LabService;
use App\Services\RadiologyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The lab and radiology report PDFs for the app (the web's own documents,
 * behind the web's permissions), and the two worklists' paging, so the app can
 * say "50 of 212" and page on (docs/api/lab-radiology.md).
 */
class LabRadiologyDocumentsApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->h = $this->hospital();
        $this->withHospital($this->h);
    }

    private function staff(string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $this->h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function visit(?Hospital $h = null): Visit
    {
        $h ??= $this->h;
        $p = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Ruth', 'last_name' => 'Kobusingye']);

        return Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id]);
    }

    private function labOrder(?Hospital $h = null): LabOrder
    {
        $h ??= $this->h;
        $this->withHospital($h);
        $test = LabTest::factory()->create(['hospital_id' => $h->id]);
        $order = app(LabService::class)->order($this->visit($h)->fresh(), [$test->id]);
        $this->withHospital($this->h);

        return $order;
    }

    private function radiologyOrder(?Hospital $h = null): RadiologyOrder
    {
        $h ??= $this->h;
        $this->withHospital($h);
        $study = RadiologyStudy::factory()->create(['hospital_id' => $h->id]);
        $order = app(RadiologyService::class)->order($this->visit($h)->fresh(), [$study->id]);
        $this->withHospital($this->h);

        return $order;
    }

    public function test_the_lab_report_is_the_web_document(): void
    {
        $order = $this->labOrder();
        Sanctum::actingAs($this->staff('lab_technician'));

        $res = $this->get("/api/v1/lab-orders/{$order->uuid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
        $this->assertStringContainsString('lab-report-'.$order->uuid.'.pdf', (string) $res->headers->get('content-disposition'));
    }

    public function test_the_radiology_report_is_the_web_document(): void
    {
        $order = $this->radiologyOrder();
        Sanctum::actingAs($this->staff('radiologist'));

        $res = $this->get("/api/v1/radiology-orders/{$order->uuid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
        $this->assertStringContainsString('radiology-report-'.$order->uuid.'.pdf', (string) $res->headers->get('content-disposition'));
    }

    public function test_each_report_needs_its_bench_permission(): void
    {
        $lab = $this->labOrder();
        $radiology = $this->radiologyOrder();

        $this->getJson("/api/v1/lab-orders/{$lab->uuid}/pdf")->assertUnauthorized();

        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson("/api/v1/lab-orders/{$lab->uuid}/pdf")->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson("/api/v1/radiology-orders/{$radiology->uuid}/pdf")->assertForbidden();

        Sanctum::actingAs($this->staff('lab_technician'));
        $this->getJson("/api/v1/radiology-orders/{$radiology->uuid}/pdf")->assertForbidden();

        Sanctum::actingAs($this->staff('radiologist'));
        $this->getJson("/api/v1/lab-orders/{$lab->uuid}/pdf")->assertForbidden();

        // The doctor who orders both reads both.
        Sanctum::actingAs($this->staff('doctor'));
        $this->get("/api/v1/lab-orders/{$lab->uuid}/pdf")->assertOk();
        $this->get("/api/v1/radiology-orders/{$radiology->uuid}/pdf")->assertOk();
    }

    public function test_another_hospitals_report_is_not_found(): void
    {
        $other = $this->hospital();
        $lab = $this->labOrder($other);
        $radiology = $this->radiologyOrder($other);

        Sanctum::actingAs($this->staff('hospital_admin'));
        $this->getJson("/api/v1/lab-orders/{$lab->uuid}/pdf")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson("/api/v1/radiology-orders/{$radiology->uuid}/pdf")->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_both_worklists_say_how_many_there_are_and_page_on(): void
    {
        foreach (range(1, 3) as $i) {
            $this->radiologyOrder();
            $this->labOrder();
        }
        Sanctum::actingAs($this->staff('hospital_admin'));

        foreach (['radiology-orders', 'lab-orders'] as $list) {
            $this->getJson("/api/v1/{$list}?per_page=2")->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('meta.total', 3)
                ->assertJsonPath('meta.last_page', 2)
                ->assertJsonPath('meta.current_page', 1);
            $this->getJson("/api/v1/{$list}?per_page=2&page=2")->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('meta.current_page', 2);
        }
    }
}
