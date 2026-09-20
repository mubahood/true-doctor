<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientDocument;
use App\Support\CurrentHospital;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Patient document storage. Files live on the *private* `local` disk under a
 * per-hospital/per-patient path — never web-served directly; downloads stream
 * through PatientDocumentController after a Policy check (C12: PHI at rest).
 */
class DocumentService
{
    private const DISK = 'local';

    public function store(Patient $patient, UploadedFile $file, string $type, ?string $note, ?int $uploadedBy = null): PatientDocument
    {
        $hospitalId = app(CurrentHospital::class)->id();
        if ($hospitalId === null) {
            throw new RuntimeException('Cannot store a document with no resolved hospital.');
        }

        $dir = "patients/{$hospitalId}/{$patient->id}";
        $name = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs($dir, $name, self::DISK);

        return PatientDocument::create([
            'uuid' => (string) Str::uuid(),
            'patient_id' => $patient->id,
            'type' => $type,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'note' => $note,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public function delete(PatientDocument $document): void
    {
        if ($this->disk()->exists($document->file_path)) {
            $this->disk()->delete($document->file_path);
        }
        $document->delete();
    }
}
