<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\TreatmentRecord;
use App\Support\CurrentHospital;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Treatment records + their photos. Photos live on the private `local` disk
 * (never web-served) under treatments/<hospital>/<record>/; the record and its
 * photo rows are created together in one transaction.
 */
class TreatmentService
{
    private const DISK = 'local';

    /**
     * @param  array<int,UploadedFile>  $photos
     */
    public function create(Patient $patient, array $data, array $photos, ?int $by = null): TreatmentRecord
    {
        $hospitalId = app(CurrentHospital::class)->id();
        if ($hospitalId === null) {
            throw new RuntimeException('Cannot create a treatment record with no resolved hospital.');
        }

        return DB::transaction(function () use ($patient, $data, $photos, $by, $hospitalId) {
            $record = TreatmentRecord::create([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'visit_id' => $data['visit_id'] ?? app(VisitService::class)->currentOrOpenFor($patient, $by)->id,
                'performed_by' => $by,
                'procedure' => $data['procedure'],
                'description' => $data['description'] ?? null,
                'meta' => $data['meta'] ?? null,
                'performed_at' => $data['performed_at'] ?? Carbon::now(),
            ]);

            foreach ($photos as $photo) {
                $name = Str::uuid().'.'.$photo->getClientOriginalExtension();
                $path = $photo->storeAs("treatments/{$hospitalId}/{$record->id}", $name, self::DISK);
                $record->photos()->create(['photo_path' => $path]);
            }

            return $record;
        });
    }

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public function delete(TreatmentRecord $record): void
    {
        foreach ($record->photos as $photo) {
            if ($this->disk()->exists($photo->photo_path)) {
                $this->disk()->delete($photo->photo_path);
            }
        }
        $record->delete();
    }
}
