<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\TreatmentRecord;
use App\Models\TreatmentRecordItem;
use App\Services\TreatmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one treatment path that cannot be Livewire: the photo stream. Photos live
 * on the private disk and are never a public URL, so they are served by a real
 * request behind the Policy. The record page itself is
 * App\Livewire\Patients\TreatmentShow; creating and removing records is
 * App\Livewire\Patients\Panels\Treatments.
 */
class TreatmentRecordController extends Controller
{
    public function __construct(private readonly TreatmentService $service) {}

    public function photo(Patient $patient, TreatmentRecordItem $item): StreamedResponse
    {
        $this->authorize('viewAny', TreatmentRecord::class);
        abort_unless($item->record->patient_id === $patient->id, 404);
        abort_unless($this->service->disk()->exists($item->photo_path), 404);

        return $this->service->disk()->response($item->photo_path, null, [
            'Content-Disposition' => 'inline; filename="'.basename($item->photo_path).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
