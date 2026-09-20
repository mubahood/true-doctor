<?php

namespace Tests\Feature;

use App\Enums\DoseRecordStatus;
use App\Livewire\Visits\Panels\Prescriptions as PrescriptionsPanel;
use App\Models\DoseItemRecord;
use App\Models\Hospital;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Visit;
use App\Services\PrescriptionService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prescribing and dose administration. Both moved from PrescriptionController to
 * the Prescriptions panel of the visit workspace (Phase 3); the schedule is
 * still expanded by PrescriptionService + DosageScheduleGenerator.
 */
class PrescriptionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading();   // the panel is #[Lazy]; test it mounted
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function acting(Hospital $h, string $role): User
    {
        $u = $this->user($h, $role);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_doctor_prescribes_and_schedule_is_generated(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(PrescriptionsPanel::class, ['visitId' => $c->id])
            ->call('openForm')
            ->set('notes', 'Course of antibiotics')
            ->set('items.0.drug_name', 'Amoxicillin')
            ->set('items.0.dosage', '500mg')
            ->set('items.0.slots', ['morning', 'night'])
            ->set('items.0.days', 3)
            ->call('prescribe')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $rx = Prescription::firstOrFail();
        $this->assertSame('Course of antibiotics', $rx->notes);
        $this->assertSame(1, $rx->doseItems()->count());
        $this->assertSame(6, DoseItemRecord::count()); // 2 slots × 3 days
    }

    /** The repeater: several drugs in one script, each with its own schedule. */
    public function test_the_repeater_writes_every_row_in_one_prescription(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(PrescriptionsPanel::class, ['visitId' => $c->id])
            ->call('openForm')
            ->set('items.0.drug_name', 'Amoxicillin')
            ->set('items.0.slots', ['morning', 'night'])
            ->set('items.0.days', 3)
            ->call('addRow')
            ->assertCount('items', 2)
            ->set('items.1.drug_name', 'Paracetamol')
            ->set('items.1.dosage', '1g')
            ->set('items.1.slots', ['morning'])
            ->set('items.1.days', 2)
            ->call('prescribe')
            ->assertHasNoErrors()
            ->assertCount('items', 1);      // the repeater resets to one blank row

        $rx = Prescription::firstOrFail();
        $this->assertSame(1, Prescription::count());
        $this->assertSame(2, $rx->doseItems()->count());
        $this->assertSame(['Amoxicillin', 'Paracetamol'], $rx->doseItems()->orderBy('id')->pluck('drug_name')->all());
        $this->assertSame(8, DoseItemRecord::count()); // (2 × 3) + (1 × 2)
    }

    public function test_a_repeater_row_can_be_removed(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(PrescriptionsPanel::class, ['visitId' => $c->id])
            ->call('openForm')
            ->set('items.0.drug_name', 'Amoxicillin')
            ->set('items.0.slots', ['morning'])
            ->call('addRow')
            ->set('items.1.drug_name', 'Dropme')
            ->call('removeRow', 1)
            ->assertCount('items', 1)
            ->call('prescribe')
            ->assertHasNoErrors();

        $this->assertSame(1, Prescription::firstOrFail()->doseItems()->count());
        $this->assertDatabaseMissing('dose_items', ['drug_name' => 'Dropme']);
    }

    public function test_a_row_without_slots_is_rejected_inline(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(PrescriptionsPanel::class, ['visitId' => $c->id])
            ->call('openForm')
            ->set('items.0.drug_name', 'Amoxicillin')
            ->set('items.0.slots', [])
            ->call('prescribe')
            ->assertHasErrors('items.0.slots');

        $this->assertDatabaseCount('prescriptions', 0);
    }

    public function test_nurse_cannot_prescribe(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'nurse');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        // The panel renders (the nurse administers from it) but writing is refused.
        Livewire::test(PrescriptionsPanel::class, ['visitId' => $c->id])
            ->assertOk()
            ->set('items.0.drug_name', 'X')
            ->set('items.0.slots', ['morning'])
            ->set('items.0.days', 1)
            ->call('prescribe')
            ->assertForbidden();

        $this->assertDatabaseCount('prescriptions', 0);
    }

    public function test_nurse_administers_a_dose_but_doctor_cannot(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        app(PrescriptionService::class)->prescribe($c, [
            ['drug_name' => 'Amox', 'slots' => ['morning'], 'days' => 1],
        ], null, null);
        $rec = DoseItemRecord::firstOrFail();

        $this->acting($h, 'doctor');
        Livewire::test(PrescriptionsPanel::class, ['visitId' => $c->id])
            ->call('markDose', $rec->id, 'administered')
            ->assertForbidden();
        $this->assertSame(DoseRecordStatus::Pending, $rec->fresh()->status);

        $nurse = $this->acting($h, 'nurse');
        Livewire::test(PrescriptionsPanel::class, ['visitId' => $c->id])
            ->call('markDose', $rec->id, 'administered')
            ->assertHasNoErrors();

        $this->assertSame(DoseRecordStatus::Administered, $rec->fresh()->status);
        $this->assertSame($nurse->id, $rec->fresh()->administered_by);
    }

    public function test_a_dose_can_be_marked_missed(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        app(PrescriptionService::class)->prescribe($c, [
            ['drug_name' => 'Amox', 'slots' => ['morning'], 'days' => 1],
        ], null, null);
        $rec = DoseItemRecord::firstOrFail();

        $this->acting($h, 'nurse');
        Livewire::test(PrescriptionsPanel::class, ['visitId' => $c->id])
            ->call('markDose', $rec->id, 'missed')
            ->assertHasNoErrors();

        $this->assertSame(DoseRecordStatus::Missed, $rec->fresh()->status);
        $this->assertNull($rec->fresh()->administered_by);
    }

    public function test_an_unknown_dose_status_is_refused(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        app(PrescriptionService::class)->prescribe($c, [
            ['drug_name' => 'Amox', 'slots' => ['morning'], 'days' => 1],
        ], null, null);
        $rec = DoseItemRecord::firstOrFail();

        $this->acting($h, 'nurse');
        Livewire::test(PrescriptionsPanel::class, ['visitId' => $c->id])
            ->call('markDose', $rec->id, 'pending')
            ->assertHasErrors('status');

        $this->assertSame(DoseRecordStatus::Pending, $rec->fresh()->status);
    }

    /** A dose record from another visit cannot be reached from this panel. */
    public function test_a_dose_from_another_visit_cannot_be_marked(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $mine = Visit::factory()->create(['hospital_id' => $h->id]);
        $other = Visit::factory()->create(['hospital_id' => $h->id]);
        app(PrescriptionService::class)->prescribe($other, [
            ['drug_name' => 'Amox', 'slots' => ['morning'], 'days' => 1],
        ], null, null);
        $foreign = DoseItemRecord::firstOrFail();

        $this->acting($h, 'nurse');

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(PrescriptionsPanel::class, ['visitId' => $mine->id])
            ->call('markDose', $foreign->id, 'administered');
    }

    public function test_prescription_is_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $consultB = Visit::factory()->create(['hospital_id' => $b->id]);
        $this->acting($a, 'doctor');

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(PrescriptionsPanel::class, ['visitId' => $consultB->id]);
    }
}
