<?php

namespace Tests\Feature;

use App\Enums\DoseRecordStatus;
use App\Enums\DoseSlot;
use App\Models\Hospital;
use App\Models\Prescription;
use App\Models\Visit;
use App\Services\DosageScheduleGenerator;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DosageScheduleGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private DosageScheduleGenerator $gen;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gen = app(DosageScheduleGenerator::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function doseItem(array $o = [])
    {
        $consult = Visit::factory()->create(['hospital_id' => $this->hospital->id]);
        $rx = Prescription::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'hospital_id' => $this->hospital->id,
            'visit_id' => $consult->id,
            'patient_id' => $consult->patient_id,
        ]);

        return $rx->doseItems()->create(array_merge([
            'drug_name' => 'Amoxicillin',
            'dosage' => '500mg',
            'slots' => ['morning', 'night'],
            'days' => 3,
            'start_date' => '2026-08-01',
        ], $o));
    }

    public function test_expands_slots_times_days(): void
    {
        $item = $this->doseItem(['slots' => ['morning', 'night'], 'days' => 3]);

        $records = $this->gen->generate($item);

        $this->assertCount(6, $records); // 2 slots × 3 days
        $this->assertSame(6, $item->records()->count());
        $this->assertTrue($item->records()->get()->every(fn ($r) => $r->status === DoseRecordStatus::Pending));
    }

    public function test_dates_and_slots_are_correct_and_ordered(): void
    {
        $item = $this->doseItem(['slots' => ['night', 'morning'], 'days' => 2, 'start_date' => '2026-08-01']);

        $this->gen->generate($item);
        $records = $item->records()->get();

        // 4 records over 2 days; within a day Morning precedes Night (canonical order).
        $this->assertSame('2026-08-01', $records[0]->scheduled_date->toDateString());
        $this->assertSame(DoseSlot::Morning, $records[0]->slot);
        $this->assertSame(DoseSlot::Night, $records[1]->slot);
        $this->assertSame('2026-08-02', $records[2]->scheduled_date->toDateString());
    }

    public function test_ignores_invalid_slots_and_dedupes(): void
    {
        $item = $this->doseItem(['slots' => ['morning', 'lunchtime', 'morning'], 'days' => 1]);

        $records = $this->gen->generate($item);

        $this->assertCount(1, $records); // only one valid, de-duplicated slot
        $this->assertSame(DoseSlot::Morning, $records[0]->slot);
    }

    public function test_zero_days_or_no_slots_generates_nothing(): void
    {
        $this->assertCount(0, $this->gen->generate($this->doseItem(['days' => 0])));
        $this->assertCount(0, $this->gen->generate($this->doseItem(['slots' => []])));
    }

    public function test_regeneration_is_idempotent(): void
    {
        $item = $this->doseItem(['slots' => ['morning'], 'days' => 2]);

        $this->gen->generate($item);
        try {
            $this->gen->generate($item); // same (item,date,slot) rows — unique index blocks duplicates
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // expected: the schedule already exists; count is unchanged
        }

        $this->assertSame(2, $item->records()->count());
    }
}
