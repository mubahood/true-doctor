<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\DentalFinding;
use App\Enums\DentalTreatmentStatus;
use App\Http\Controllers\Controller;
use App\Models\DentalChartEntry;
use App\Models\DentalTreatmentItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Visit;
use App\Services\DentalService;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The dental chart and treatment plan for the app (docs/api/dental.md): the
 * same DentalService as the web's dental tab.
 */
class DentalController extends Controller
{
    public function __construct(private readonly DentalService $dental) {}

    /** GET patients/{patient}/dental */
    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->clinical($request);
        $this->can($request, ['treatments.view', 'visits.diagnose']);

        $asOf = $request->query('as_of') ? Carbon::parse((string) $request->query('as_of'))->endOfDay() : null;

        return ApiResponse::success([
            'enabled' => DentalService::enabledFor(\App\Models\Hospital::find(app(CurrentHospital::class)->id())),
            'patient_uuid' => $patient->uuid,
            'chart' => array_values($this->dental->chart($patient, $asOf)),
            'plan' => $this->dental->plan($patient)->map(fn (DentalTreatmentItem $i) => $this->item($i))->values(),
            'history' => $this->dental->history($patient, 50)->map(fn (DentalChartEntry $e) => [
                'uuid' => $e->uuid, 'tooth' => $e->tooth, 'surfaces' => $e->surfaces, 'finding' => $e->finding->value,
                'finding_label' => $e->finding->label(), 'mobility' => $e->mobility, 'notes' => $e->notes,
                'recorded_at' => $e->recorded_at->toIso8601String(), 'recorded_by' => $e->recordedBy?->name,
            ])->values(),
            'next_recall_on' => $patient->next_recall_on?->toDateString(),
            'findings' => array_map(fn (DentalFinding $f) => ['value' => $f->value, 'label' => $f->label(), 'tone' => $f->tone(), 'whole_tooth' => $f->wholeTooth()], DentalFinding::cases()),
        ]);
    }

    /** POST patients/{patient}/dental/findings - one or more findings; a repeated uuid is not charted twice. */
    public function record(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->clinical($request);
        $this->can($request, ['visits.diagnose']);
        $data = $request->validate([
            'findings' => ['required', 'array', 'min:1', 'max:64'],
            'findings.*.uuid' => ['nullable', 'uuid'],
            'findings.*.tooth' => ['required', 'integer'],
            'findings.*.finding' => ['required', 'string'],
            'findings.*.surfaces' => ['nullable', 'string', 'max:12'],
            'findings.*.mobility' => ['nullable', 'integer', 'min:0', 'max:3'],
            'findings.*.notes' => ['nullable', 'string', 'max:500'],
            'visit_uuid' => ['nullable', 'uuid'],
        ]);

        $made = $this->dental->record($patient, $data['findings'], $this->visit($data['visit_uuid'] ?? null, $patient), $request->user());

        return ApiResponse::success([
            'recorded' => array_map(fn (DentalChartEntry $e) => $e->uuid, $made),
            'chart' => array_values($this->dental->chart($patient)),
        ], 'Recorded.', 201);
    }

    /** POST patients/{patient}/dental/plan */
    public function plan(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->clinical($request);
        $this->can($request, ['visits.diagnose']);
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'tooth' => ['nullable', 'integer'],
            'surfaces' => ['nullable', 'string', 'max:12'],
            'price' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
            'visit_uuid' => ['nullable', 'uuid'],
        ]);

        $item = $this->dental->planItem(
            $patient, Service::query()->findOrFail($data['service_id']), $data['tooth'] ?? null, $data['surfaces'] ?? null,
            $data['price'] ?? null, $data['notes'] ?? null, $this->visit($data['visit_uuid'] ?? null, $patient), $request->user(),
        );

        return ApiResponse::success($this->item($item->load('service')), 'Added to the plan.', 201);
    }

    /** POST dental/plan/{item}/status - accept, start, done (bills on the open visit) or cancel. */
    public function status(Request $request, DentalTreatmentItem $item): JsonResponse
    {
        $this->authorize('view', $item->patient);
        $this->clinical($request);
        $this->can($request, ['treatments.manage', 'visits.diagnose']);
        $data = $request->validate([
            'status' => ['required', 'string'],
            'visit_uuid' => ['nullable', 'uuid'],
        ]);
        $to = DentalTreatmentStatus::tryFrom($data['status']);
        if ($to === null) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, 'Unknown status.', 422, ['status' => ['Unknown status.']]);
        }

        $moved = $this->dental->transition($item, $to, $request->user(), $this->visit($data['visit_uuid'] ?? null, $item->patient));

        return ApiResponse::success($this->item($moved->load('service', 'doneBy')), $to === DentalTreatmentStatus::Done ? 'Done, and billed on the visit.' : 'Updated.');
    }

    /** PUT patients/{patient}/recall - a date, "default" (six months) or null to clear. */
    public function recall(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->clinical($request);
        $this->can($request, ['patients.update', 'visits.diagnose']);
        $value = $request->input('next_recall_on');
        $on = $value === 'default' ? DentalService::defaultRecall() : ($value ? Carbon::parse((string) $value) : null);

        $this->dental->setRecall($patient, $on);

        return ApiResponse::success(['next_recall_on' => $on?->toDateString()]);
    }

    /** GET dental/report?from=&to= */
    public function report(Request $request): JsonResponse
    {
        $this->can($request, ['reports.view', 'visits.diagnose']);
        $from = Carbon::parse((string) $request->query('from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse((string) $request->query('to', now()->toDateString()));

        return ApiResponse::success(['from' => $from->toDateString(), 'to' => $to->toDateString()] + $this->dental->report($from, $to));
    }

    /** The chart is part of the clinical record (Staff and access, "See clinical notes"). */
    private function clinical(Request $request): void
    {
        abort_unless($request->user()->can('visits.clinical'), 403, 'You do not have access to this. Ask your hospital admin.');
    }

    /** @param list<string> $any */
    private function can(Request $request, array $any): void
    {
        abort_unless(collect($any)->contains(fn ($p) => $request->user()->can($p)), 403, 'Your role cannot do that.');
    }

    private function visit(?string $uuid, Patient $patient): ?Visit
    {
        if ($uuid === null) {
            return null;
        }

        return Visit::query()->where('uuid', $uuid)->where('patient_id', $patient->id)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function item(DentalTreatmentItem $i): array
    {
        return [
            'uuid' => $i->uuid,
            'service_id' => $i->service_id,
            'procedure' => $i->service?->name,
            'tooth' => $i->tooth,
            'surfaces' => $i->surfaces,
            'price' => (string) $i->price,
            'price_label' => HospitalSettings::money($i->price),
            'status' => $i->status->value,
            'status_label' => $i->status->label(),
            'tone' => $i->status->tone(),
            'next_statuses' => array_map(fn (DentalTreatmentStatus $s) => $s->value, $i->status->transitionsTo()),
            'notes' => $i->notes,
            'done_at' => $i->done_at?->toIso8601String(),
            'done_by' => $i->doneBy?->name,
            'billed' => $i->order_item_id !== null,
        ];
    }
}
