<?php

namespace Tests\Feature;

use App\Enums\LabOrderStatus;
use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Services\LabService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What the measured journeys found (docs/roadmap/journeys.md). */
class JourneyFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_phone_number_is_found_however_it_is_written(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $p = Patient::factory()->create(['hospital_id' => $h->id, 'phone_1' => '0701115949']);

        foreach (['+256701115949', '256701115949', '0701115949', '701115949'] as $typed) {
            $this->assertSame([$p->id], Patient::query()->search($typed)->pluck('id')->all(), $typed);
        }
    }

    public function test_reporting_results_completes_an_order_still_marked_ordered(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $p = Patient::factory()->create(['hospital_id' => $h->id]);
        $v = \App\Models\Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id]);
        $order = LabOrder::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'hospital_id' => $h->id, 'visit_id' => $v->id, 'patient_id' => $p->id, 'status' => LabOrderStatus::Ordered]);

        $done = app(LabService::class)->complete($order);

        $this->assertSame(LabOrderStatus::Completed, $done->status);
        $this->assertNotNull($done->completed_at);
    }

    public function test_signing_off_a_report_completes_an_order_still_marked_ordered(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $p = Patient::factory()->create(['hospital_id' => $h->id]);
        $v = \App\Models\Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id]);
        $order = \App\Models\RadiologyOrder::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'hospital_id' => $h->id, 'visit_id' => $v->id, 'patient_id' => $p->id, 'status' => \App\Enums\RadiologyOrderStatus::Ordered]);

        $done = app(\App\Services\RadiologyService::class)->signOff($order);

        $this->assertSame(\App\Enums\RadiologyOrderStatus::Reported, $done->status);
    }
}
