<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\MedicationAdministration;
use App\Models\NursingNote;
use App\Models\VitalRound;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The append-only bedside logs of an admission (observations, the medication
 * record, nursing notes). Shared by the workspace panels and the API so the
 * "only an active admission accepts entries" rule lives once.
 */
class NursingChartService
{
    /** @param  array<string,mixed>  $data  validated VitalRoundRequest fields */
    public function recordVitals(Admission $admission, array $data, ?int $by): VitalRound
    {
        $this->assertOpen($admission);

        return $admission->vitalRounds()->create($data + ['recorded_by' => $by, 'created_at' => Carbon::now()]);
    }

    /** @param  array<string,mixed>  $data  validated MedicationAdministrationRequest fields */
    public function recordMedication(Admission $admission, array $data, ?int $by): MedicationAdministration
    {
        $this->assertOpen($admission);

        return $admission->medications()->create($data + ['administered_by' => $by, 'created_at' => Carbon::now()]);
    }

    /** @param  array<string,mixed>  $data  validated NursingNoteRequest fields */
    public function recordNote(Admission $admission, array $data, ?int $by): NursingNote
    {
        $this->assertOpen($admission);

        return $admission->nursingNotes()->create($data + ['recorded_by' => $by, 'created_at' => Carbon::now()]);
    }

    private function assertOpen(Admission $admission): void
    {
        if (! $admission->status->isActive()) {
            throw new RuntimeException('This admission is closed.');
        }
    }
}
