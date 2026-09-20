<?php

namespace Tests\Feature;

use App\Enums\AdmissionStatus;
use App\Enums\BedStatus;
use App\Exceptions\BedUnavailableException;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdmissionService $svc;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AdmissionService::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function bed(string $charge = '50.00'): Bed
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);

        return Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id, 'daily_charge' => $charge]);
    }

    private function patient(): Patient
    {
        return Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    public function test_admit_occupies_the_bed(): void
    {
        $bed = $this->bed();
        $admission = $this->svc->admit($this->patient(), $bed, ['reason' => 'Observation']);

        $this->assertSame(AdmissionStatus::Admitted, $admission->status);
        $this->assertSame(BedStatus::Occupied, $bed->fresh()->status);
    }

    public function test_cannot_admit_to_an_occupied_bed(): void
    {
        $bed = $this->bed();
        $this->svc->admit($this->patient(), $bed, []);

        $this->expectException(BedUnavailableException::class);
        $this->svc->admit($this->patient(), $bed->fresh(), []);
    }

    public function test_transfer_frees_old_bed_and_occupies_new(): void
    {
        $bedA = $this->bed();
        $bedB = $this->bed();
        $admission = $this->svc->admit($this->patient(), $bedA, []);

        $this->svc->transfer($admission, $bedB, 'Closer to nurses');

        $this->assertSame(BedStatus::Available, $bedA->fresh()->status);
        $this->assertSame(BedStatus::Occupied, $bedB->fresh()->status);
        $this->assertSame($bedB->id, $admission->fresh()->bed_id);
        $this->assertSame(1, $admission->transfers()->count());
    }

    public function test_discharge_frees_bed_and_bills_nights_to_the_visit(): void
    {
        $patient = $this->patient();
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $bed = $this->bed('40.00');

        $admission = $this->svc->admit($patient, $bed, [
            'visit_id' => $visit->id,
            'admitted_at' => Carbon::now()->subDays(3),
        ]);

        $this->svc->discharge($admission->fresh(), AdmissionStatus::Discharged, 'Recovered');

        $admission->refresh();
        $this->assertSame(AdmissionStatus::Discharged, $admission->status);
        $this->assertSame(BedStatus::Available, $bed->fresh()->status);
        $this->assertSame('120.00', (string) $admission->bed_charge_total); // 3 × 40

        // Billed to the visit as ONE ITEM PER NIGHT, not one line for the
        // stay: the bill reads as the nights it actually was, and a rate
        // change between them prices only the nights that follow it.
        $this->assertSame(3, $visit->orderItems()->count());
        $this->assertSame(3, $admission->nights_billed);
        $this->assertSame(
            '120.00',
            $visit->orderItems()->get()->reduce(fn (string $sum, $i) => bcadd($sum, (string) $i->line_total, 2), '0.00'),
        );
        // Each one says which night it covers.
        $this->assertStringContainsString('night of', $visit->orderItems()->first()->name);
    }

    public function test_cannot_discharge_twice(): void
    {
        $bed = $this->bed();
        $admission = $this->svc->admit($this->patient(), $bed, []);
        $this->svc->discharge($admission->fresh(), AdmissionStatus::Discharged);

        $this->expectException(\RuntimeException::class);
        $this->svc->discharge($admission->fresh(), AdmissionStatus::Discharged);
    }

    public function test_minimum_one_night_is_charged(): void
    {
        $patient = $this->patient();
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $bed = $this->bed('30.00');
        $admission = $this->svc->admit($patient, $bed, ['visit_id' => $visit->id]); // admitted now

        $this->svc->discharge($admission->fresh(), AdmissionStatus::Discharged);

        $this->assertSame('30.00', (string) $admission->fresh()->bed_charge_total); // same-day = 1 night
    }

    // ── A stay is an order ───────────────────────────────────────────────

    /**
     * Admitting raises the order that IS the stay, and leaves it open.
     *
     * It used to raise nothing until discharge, so an active admission was
     * invisible in the visit's one list of work — and the visit's own gate
     * ("no order still open") could not see that the patient was in a bed.
     */
    public function test_admitting_raises_an_open_order_pointing_at_the_stay(): void
    {
        $patient = $this->patient();
        $visit = app(\App\Services\VisitService::class)->open(['patient_id' => $patient->id]);

        $admission = $this->svc->admit($patient, $this->bed(), [
            'visit_id' => $visit->id, 'title' => 'Admit — general ward', 'reason' => 'IV fluids',
        ]);

        /** @var \App\Models\Order $stay */
        $stay = \App\Models\Order::where('subject_type', $admission->getMorphClass())
            ->where('subject_id', $admission->id)->firstOrFail();

        $this->assertSame(\App\Enums\OrderType::Admission, $stay->type);
        $this->assertSame(\App\Enums\OrderStatus::InProgress, $stay->status);
        $this->assertSame('Admit — general ward', $stay->title);
        $this->assertSame($visit->id, $stay->visit_id);
        $this->assertSame(0, $stay->items()->count(), 'the stay was billed before it happened');
    }

    /** So a visit cannot reach billing while its patient is still in a bed. */
    public function test_a_visit_cannot_be_billed_while_the_patient_is_admitted(): void
    {
        $patient = $this->patient();
        $visit = app(\App\Services\VisitService::class)->open(['patient_id' => $patient->id]);
        $admission = $this->svc->admit($patient, $this->bed(), ['visit_id' => $visit->id]);

        $gate = app(\App\Services\VisitService::class)->readiness($visit->fresh());
        $this->assertFalse($gate['ready'], 'the visit could be billed with the patient still admitted');
        $this->assertSame('1 order is still open.', $gate['blocker']);

        $this->svc->discharge($admission, AdmissionStatus::Discharged);

        $this->assertTrue(app(\App\Services\VisitService::class)->readiness($visit->fresh())['ready']);
    }

    /** Discharge finishes THAT order and bills it — it does not invent a second. */
    public function test_discharge_completes_the_stays_own_order(): void
    {
        $patient = $this->patient();
        $visit = app(\App\Services\VisitService::class)->open(['patient_id' => $patient->id]);
        $admission = $this->svc->admit($patient, $this->bed('50.00'), [
            'visit_id' => $visit->id, 'admitted_at' => Carbon::now()->subDays(3),
        ]);

        $this->svc->discharge($admission, AdmissionStatus::Discharged, 'Recovered');

        $orders = \App\Models\Order::where('visit_id', $visit->id)
            ->where('type', \App\Enums\OrderType::Admission->value)->get();

        $this->assertCount(1, $orders, 'discharge invented a second order for the same stay');
        $this->assertSame(\App\Enums\OrderStatus::Completed, $orders->first()->status);
        // Three nights, three items, 50 each — the stay's own order
        // carries the nights rather than one multiplication of them.
        $items = $orders->first()->items()->get();
        $this->assertCount(3, $items);
        $this->assertSame(
            '150.00',
            $items->reduce(fn (string $sum, $i) => bcadd($sum, (string) $i->line_total, 2), '0.00'),
        );
    }

    /**
     * One patient, one bed.
     *
     * Nothing used to stop a second admission being raised while the first was
     * live, which put one patient in two beds and billed both of them.
     */
    public function test_a_patient_cannot_be_admitted_twice(): void
    {
        $patient = $this->patient();
        $first = $this->bed();
        $second = $this->bed();

        $this->svc->admit($patient, $first, []);

        try {
            $this->svc->admit($patient, $second, []);
            $this->fail('a patient was admitted to two beds at once');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already admitted', $e->getMessage());
        }

        $this->assertSame(BedStatus::Available, $second->fresh()->status, 'the second bed was taken anyway');
    }

    /** And can be admitted again once discharged. */
    public function test_a_discharged_patient_can_be_admitted_again(): void
    {
        $patient = $this->patient();
        $admission = $this->svc->admit($patient, $this->bed(), []);
        $this->svc->discharge($admission, AdmissionStatus::Discharged);

        $again = $this->svc->admit($patient, $this->bed(), []);

        $this->assertSame(AdmissionStatus::Admitted, $again->status);
    }

    /**
     * A stay in a bed that costs nothing still finishes.
     *
     * A completed order has to carry evidence of the work, and a free bed adds
     * no charge — so the discharge writes the stay's own report instead of
     * finding itself blocked by a rule meant for empty orders.
     */
    public function test_a_free_bed_can_still_be_discharged(): void
    {
        $patient = $this->patient();
        $admission = $this->svc->admit($patient, $this->bed('0.00'), []);

        $this->svc->discharge($admission, AdmissionStatus::Discharged, 'Walked out well');

        /** @var \App\Models\Order $stay */
        $stay = \App\Models\Order::where('subject_id', $admission->id)
            ->where('subject_type', $admission->getMorphClass())->firstOrFail();

        $this->assertSame(\App\Enums\OrderStatus::Completed, $stay->status);
        $this->assertSame(0, $stay->items()->count(), 'a free bed was billed anyway');
        $this->assertStringContainsString('Discharged after', (string) $stay->report);
        $this->assertStringContainsString('Walked out well', (string) $stay->report);
    }

    /** A report already written by hand is not overwritten by the discharge. */
    public function test_a_stays_own_report_survives_the_discharge(): void
    {
        $patient = $this->patient();
        $admission = $this->svc->admit($patient, $this->bed('50.00'), []);

        /** @var \App\Models\Order $stay */
        $stay = \App\Models\Order::where('subject_id', $admission->id)
            ->where('subject_type', $admission->getMorphClass())->firstOrFail();
        app(\App\Services\OrderService::class)->saveReport($stay, 'Daily rounds recorded on the ward.');

        $this->svc->discharge($admission, AdmissionStatus::Discharged, 'Home');

        $this->assertSame('Daily rounds recorded on the ward.', (string) $stay->fresh()->report);
    }
}
