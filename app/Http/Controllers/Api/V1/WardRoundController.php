<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Api\V1\Concerns\PagesAndDeltas;
use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\HandoverNote;
use App\Models\Ward;
use App\Services\HandoverService;
use App\Services\WardRoundService;
use App\Support\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The ward nurse's round: doses due, the SBAR handover seed, and the handover
 * itself. Reading needs ipd.view, writing ipd.manage (AdmissionPolicy, as the
 * admissions the notes are about). A ward is addressed by its `id` (wards have
 * no uuid); `ward_uuid` is accepted as the name of that parameter.
 */
class WardRoundController extends Controller
{
    use PagesAndDeltas;

    public function __construct(private readonly WardRoundService $round, private readonly HandoverService $handovers) {}

    public function dosesDue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Admission::class);
        $data = $request->validate(['within' => ['nullable', 'integer', 'min:0', 'max:1440']]);

        $rows = $request->user()->hospital === null
            ? collect()
            : $this->round->dosesDue($request->user()->hospital, $this->ward($request), (int) ($data['within'] ?? 60));

        return ApiResponse::withMeta($rows->all(), ['total' => $rows->count(), 'within' => (int) ($data['within'] ?? 60)]);
    }

    public function handoverSeed(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Admission::class);
        $ward = $this->ward($request);

        $since = HandoverNote::query()->when($ward, fn ($q) => $q->where('ward_id', $ward->id))->latest('created_at')->value('created_at');
        $rows = $request->user()->hospital === null ? collect()
            : $this->round->handoverSeed($request->user()->hospital, $ward, $since === null ? null : \Illuminate\Support\Carbon::parse($since));

        return ApiResponse::withMeta($rows->all(), ['total' => $rows->count(), 'since' => $since === null ? null : \Illuminate\Support\Carbon::parse($since)->toIso8601String()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('manage', Admission::class);

        $data = $request->validate([
            'ward_uuid' => ['required_without:ward_id', 'nullable'],
            'ward_id' => ['nullable'],
            'shift' => ['required', Rule::in(HandoverService::SHIFTS)],
            'notes' => ['required', 'array', 'min:1', 'max:100'],
            'notes.*.admission_uuid' => ['required', 'string', 'max:64'],
            'notes.*.situation' => ['nullable', 'string', 'max:2000'],
            'notes.*.background' => ['nullable', 'string', 'max:2000'],
            'notes.*.assessment' => ['nullable', 'string', 'max:2000'],
            'notes.*.recommendation' => ['nullable', 'string', 'max:2000'],
        ]);

        $ward = $this->ward($request) ?? abort(404);

        try {
            $note = $this->handovers->store($request->user(), $ward, $data['shift'], array_values($data['notes']));
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['notes' => [$e->getMessage()]]);
        }

        return ApiResponse::success($this->handovers->present($note), 'Handover saved.', 201);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Admission::class);
        $ward = $this->ward($request);

        $page = HandoverNote::query()->with(['ward:id,name', 'author:id,name', 'acknowledgedBy:id,name'])
            ->when($ward, fn ($q) => $q->where('ward_id', $ward->id))
            ->latest('created_at')->latest('id')->paginate($this->perPage($request));

        return ApiResponse::paginated($page, collect($page->items())->map(fn ($h) => $this->handovers->present($h))->all());
    }

    public function acknowledge(Request $request, HandoverNote $handover): JsonResponse
    {
        $this->authorize('manage', Admission::class);

        try {
            $handover = $this->handovers->acknowledge($handover, $request->user());
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->handovers->present($handover), 'Handover acknowledged.');
    }

    public function pdf(HandoverNote $handover)
    {
        $this->authorize('viewAny', Admission::class);
        $handover->load(['ward', 'author', 'acknowledgedBy']);

        return Pdf::loadView('pdf.handover', ['handover' => $handover, 'hospital' => app(\App\Support\HospitalSettings::class)->hospital()])
            ->stream('handover-'.strtoupper(substr($handover->uuid, 0, 8)).'.pdf');
    }

    /** The ward named by `ward_uuid` (or `ward_id`), or null when none was asked for; a ward of another hospital is a 404. */
    private function ward(Request $request): ?Ward
    {
        $id = $request->input('ward_uuid', $request->input('ward_id'));
        if (! filled($id)) {
            return null;
        }

        return ctype_digit((string) $id) ? Ward::findOrFail((int) $id) : abort(404);
    }
}
