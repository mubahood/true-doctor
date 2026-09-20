<?php

namespace App\Services;

use App\Enums\DoseRecordStatus;
use App\Models\DoseItemRecord;
use App\Models\Prescription;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes a prescription (header + dose items) for a visit and expands
 * each dose item into its administration schedule via DosageScheduleGenerator —
 * all in one transaction so a prescription and its schedule are never partial.
 */
class PrescriptionService
{
    public function __construct(private readonly DosageScheduleGenerator $generator) {}

    /**
     * @param  list<array{drug_name:string,dosage?:string|null,slots:array<int,string>,days:int,start_date?:string|null,instructions?:string|null}>  $items
     */
    public function prescribe(Visit $visit, array $items, ?string $notes, ?int $by = null): Prescription
    {
        return DB::transaction(function () use ($visit, $items, $notes, $by) {
            $prescription = Prescription::create([
                'uuid' => (string) Str::uuid(),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'prescribed_by' => $by,
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                $dose = $prescription->doseItems()->create([
                    'drug_name' => $item['drug_name'],
                    'dosage' => $item['dosage'] ?? null,
                    'slots' => $item['slots'],
                    'days' => (int) $item['days'],
                    'start_date' => $item['start_date'] ?? Carbon::now()->toDateString(),
                    'instructions' => $item['instructions'] ?? null,
                ]);

                $this->generator->generate($dose);
            }

            return $prescription;
        });
    }

    /** Nurse marks one scheduled dose administered or missed. */
    public function markDose(DoseItemRecord $record, DoseRecordStatus $status, ?int $by = null, ?string $note = null): DoseItemRecord
    {
        $record->status = $status;
        $record->note = $note;
        $record->administered_at = $status === DoseRecordStatus::Administered ? Carbon::now() : null;
        $record->administered_by = $status === DoseRecordStatus::Administered ? $by : null;
        $record->save();

        return $record;
    }
}
