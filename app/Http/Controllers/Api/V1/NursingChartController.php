<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\MedicationAdministrationRequest;
use App\Http\Requests\NursingNoteRequest;
use App\Http\Requests\VitalRoundRequest;
use App\Http\Resources\NursingEntryResource;
use App\Models\Admission;
use App\Services\NursingChartService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The admission workspace's three append-only panels (vitals, medication
 * record, nursing notes): read with ipd.view, append with ipd.manage on an
 * active admission — what the Livewire panels do, through NursingChartService.
 */
class NursingChartController extends Controller
{
    public function __construct(private readonly NursingChartService $chart) {}

    public function vitals(Request $request, Admission $admission): JsonResponse
    {
        return $this->list($request, $admission, 'vitalRounds', 'recordedBy');
    }

    public function medications(Request $request, Admission $admission): JsonResponse
    {
        return $this->list($request, $admission, 'medications', 'administeredBy');
    }

    public function notes(Request $request, Admission $admission): JsonResponse
    {
        return $this->list($request, $admission, 'nursingNotes', 'recordedBy');
    }

    public function storeVitals(Request $request, Admission $admission): JsonResponse
    {
        $data = $this->validated($request, $admission, VitalRoundRequest::rulesFor());
        // An untouched box is "not recorded", not an empty reading.
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        return $this->append($admission, fn () => $this->chart->recordVitals($admission, $data, $request->user()->id), 'Vitals round recorded.');
    }

    public function storeMedication(Request $request, Admission $admission): JsonResponse
    {
        $data = $this->validated($request, $admission, MedicationAdministrationRequest::rulesFor());
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        return $this->append($admission, fn () => $this->chart->recordMedication($admission, $data, $request->user()->id), 'Medication recorded.');
    }

    public function storeNote(Request $request, Admission $admission): JsonResponse
    {
        $data = $this->validated($request, $admission, NursingNoteRequest::rulesFor());

        return $this->append($admission, fn () => $this->chart->recordNote($admission, $data, $request->user()->id), 'Nursing note added.');
    }

    private function list(Request $request, Admission $admission, string $relation, string $author): JsonResponse
    {
        $this->authorize('view', $admission);

        $page = $admission->{$relation}()->with($author)->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponse::paginated($page, NursingEntryResource::collection($page->items()));
    }

    /** @param  array<string,mixed>  $rules */
    private function validated(Request $request, Admission $admission, array $rules): array
    {
        $this->authorize('manage', Admission::class);

        return $request->validate($rules);
    }

    private function append(Admission $admission, \Closure $record, string $message): JsonResponse
    {
        try {
            $row = $record();
        } catch (RuntimeException $e) {
            // Same 422 as the web panels: only an active admission accepts entries.
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success(new NursingEntryResource($row->load($row instanceof \App\Models\MedicationAdministration ? 'administeredBy' : 'recordedBy')), $message, 201);
    }
}
