<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PregnancyStatus;
use App\Http\Controllers\Controller;
use App\Models\AncContact;
use App\Models\Immunisation;
use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\Visit;
use App\Services\GrowthService;
use App\Services\ImmunisationService;
use App\Services\PregnancyService;
use App\Support\ApiResponse;
use App\Support\MotherChild\MotherChild;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * The pregnancy record, the immunisation register and the growth chart for
 * the app (docs/api/mother-child.md): the same services as the web's tabs.
 * Every write takes a client `uuid` and an Idempotency-Key, so a retry after
 * a lost reply records once.
 */
class MotherChildController extends Controller
{
    public function __construct(
        private readonly PregnancyService $pregnancies,
        private readonly ImmunisationService $immunisations,
        private readonly GrowthService $growth,
    ) {}

    // ── Pregnancy ────────────────────────────────────────────────────────

    /** GET patients/{patient}/pregnancy */
    public function pregnancy(Request $request, Patient $patient): JsonResponse
    {
        $this->canView($request, $patient);
        $all = $this->pregnancies->all($patient)->each(fn (Pregnancy $p) => $p->setRelation('patient', $patient));
        $current = $all->first(fn (Pregnancy $p) => $p->status === PregnancyStatus::Active);

        return ApiResponse::success([
            'patient_uuid' => $patient->uuid,
            'current' => $current === null ? null : $this->pregnancyRow($current, true),
            'past' => $all->reject(fn (Pregnancy $p) => $p->status === PregnancyStatus::Active)->map(fn (Pregnancy $p) => $this->pregnancyRow($p, false))->values(),
            'risk_factors' => collect(PregnancyService::riskFactors())->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'checks' => collect(PregnancyService::checks())->map(fn ($c, $key) => ['value' => $key, 'label' => $c['label']])->values(),
        ]);
    }

    /** POST patients/{patient}/pregnancies */
    public function openPregnancy(Request $request, Patient $patient): JsonResponse
    {
        $this->canRecord($request, $patient);
        $data = $request->validate($this->pregnancyRules() + ['uuid' => ['nullable', 'uuid']]);
        $p = $this->pregnancies->open($patient, $data, $request->user());

        return ApiResponse::success($this->pregnancyRow($p->setRelation('patient', $patient)->load('contacts'), true), 'Pregnancy recorded.', 201);
    }

    /** PATCH pregnancies/{pregnancy} - partial: a key not sent keeps its value. */
    public function updatePregnancy(Request $request, Pregnancy $pregnancy): JsonResponse
    {
        $this->canRecord($request, $pregnancy->patient);
        $data = $request->validate($this->pregnancyRules());
        $p = $this->pregnancies->update($pregnancy, $data, $request->user());

        return ApiResponse::success($this->pregnancyRow($p->load('contacts', 'patient'), true), 'Updated.');
    }

    /** POST pregnancies/{pregnancy}/contacts */
    public function contact(Request $request, Pregnancy $pregnancy): JsonResponse
    {
        $this->canRecord($request, $pregnancy->patient);
        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'seen_on' => ['nullable', 'date'],
            'blood_pressure' => ['nullable', 'string', 'max:12'],
            'weight' => ['nullable', 'numeric'],
            'fundal_height' => ['nullable', 'integer'],
            'fetal_heart_rate' => ['nullable', 'integer'],
            'checks' => ['nullable', 'array', 'max:40'],
            'checks.*' => ['string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
            'visit_uuid' => ['nullable', 'uuid'],
        ]);
        $contact = $this->pregnancies->recordContact($pregnancy, $data, $this->visit($data['visit_uuid'] ?? null, $pregnancy->patient), $request->user());

        return ApiResponse::success(
            ['contact' => $this->contactRow($contact), 'pregnancy' => $this->pregnancyRow($pregnancy->fresh(['contacts', 'patient']), true)],
            'Contact recorded.', 201,
        );
    }

    /** POST pregnancies/{pregnancy}/close - status delivered|ended, ended_on, notes. */
    public function close(Request $request, Pregnancy $pregnancy): JsonResponse
    {
        $this->canRecord($request, $pregnancy->patient);
        $data = $request->validate([
            'status' => ['required', 'in:delivered,ended'],
            'ended_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $p = $this->pregnancies->close($pregnancy, PregnancyStatus::from($data['status']), isset($data['ended_on']) ? Carbon::parse($data['ended_on']) : null, $data['notes'] ?? null, $request->user());

        return ApiResponse::success($this->pregnancyRow($p->load('contacts', 'patient'), false), 'Pregnancy closed.');
    }

    // ── Immunisation ─────────────────────────────────────────────────────

    /** GET patients/{patient}/immunisations */
    public function immunisations(Request $request, Patient $patient): JsonResponse
    {
        $this->canView($request, $patient);
        $card = $this->immunisations->card($patient);

        return ApiResponse::success([
            'patient_uuid' => $patient->uuid,
            'dob' => $patient->dob?->toDateString(),
            'card' => $card,
            'counts' => ImmunisationService::counts($card),
            'source' => config('mother_child.immunisation.source'),
        ]);
    }

    /** POST patients/{patient}/immunisations - one or more doses. */
    public function give(Request $request, Patient $patient): JsonResponse
    {
        $this->canRecord($request, $patient);
        $data = $request->validate([
            'doses' => ['required', 'array', 'min:1', 'max:20'],
            'doses.*.uuid' => ['nullable', 'uuid'],
            'doses.*.dose_key' => ['required', 'string', 'max:20'],
            'doses.*.given_on' => ['nullable', 'date'],
            'doses.*.given_elsewhere' => ['nullable', 'boolean'],
            'doses.*.batch_no' => ['nullable', 'string', 'max:40'],
            'doses.*.notes' => ['nullable', 'string', 'max:500'],
            'visit_uuid' => ['nullable', 'uuid'],
        ]);
        $made = $this->immunisations->record($patient, $data['doses'], $this->visit($data['visit_uuid'] ?? null, $patient), $request->user());
        $card = $this->immunisations->card($patient);

        return ApiResponse::success([
            'recorded' => array_map(fn (Immunisation $i) => $i->uuid, $made),
            'card' => $card,
            'counts' => ImmunisationService::counts($card),
        ], 'Recorded.', 201);
    }

    /** POST immunisations/{immunisation}/void - reason. */
    public function void(Request $request, Immunisation $immunisation): JsonResponse
    {
        $this->canRecord($request, $immunisation->patient);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $row = $this->immunisations->void($immunisation, $data['reason'], $request->user());

        return ApiResponse::success(['uuid' => $row->uuid, 'voided_at' => $row->voided_at?->toIso8601String()], 'Voided.');
    }

    /** GET immunisations/due?on=&q=&status=due|overdue&page= */
    public function due(Request $request): JsonResponse
    {
        abort_unless(MotherChild::canView($request->user()), 403, 'Your role cannot do that.');
        $on = $request->query('on') ? Carbon::parse((string) $request->query('on'))->startOfDay() : Carbon::today();
        $status = in_array($request->query('status'), ['due', 'overdue'], true) ? (string) $request->query('status') : '';
        $rows = $this->immunisations->dueList($on, mb_substr((string) $request->query('q', ''), 0, 100), $status);
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page);

        return ApiResponse::paginated($paginator, $paginator->getCollection()->map(fn (array $r) => [
            'patient_uuid' => $r['patient']->uuid,
            'patient_no' => $r['patient']->patient_no,
            'name' => $r['patient']->full_name,
            'age_label' => MotherChild::ageLabel(MotherChild::days($r['patient']->dob, $on)),
            'phone' => $r['patient']->phone_1,
            'overdue' => $r['overdue'],
            'doses' => array_map(fn ($d) => ['key' => $d['key'], 'vaccine' => $d['vaccine'], 'dose' => $d['dose'], 'due_on' => $d['due_on'], 'status' => $d['status']], $r['due']),
        ])->values(), ['on' => $on->toDateString()]);
    }

    // ── Growth ───────────────────────────────────────────────────────────

    /** GET patients/{patient}/growth */
    public function growth(Request $request, Patient $patient): JsonResponse
    {
        $this->canView($request, $patient);
        $points = $this->growth->points($patient);

        return ApiResponse::success([
            'patient_uuid' => $patient->uuid,
            'assessment' => $this->growth->assessment($patient, $points),
            'points' => $points,
            'source' => config('mother_child.growth.source'),
        ]);
    }

    /** POST patients/{patient}/growth */
    public function measure(Request $request, Patient $patient): JsonResponse
    {
        $this->canRecord($request, $patient);
        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'measured_on' => ['nullable', 'date'],
            'weight' => ['nullable', 'numeric'],
            'height' => ['nullable', 'numeric'],
            'muac' => ['nullable', 'numeric'],
            'oedema' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'visit_uuid' => ['nullable', 'uuid'],
        ]);
        $m = $this->growth->record($patient, $data, $this->visit($data['visit_uuid'] ?? null, $patient), $request->user());

        return ApiResponse::success([
            'uuid' => $m->uuid,
            'assessment' => $this->growth->assessment($patient),
        ], 'Measurement recorded.', 201);
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function canView(Request $request, Patient $patient): void
    {
        $this->authorize('view', $patient);
        abort_unless(MotherChild::canView($request->user()), 403, 'Your role cannot do that.');
    }

    private function canRecord(Request $request, Patient $patient): void
    {
        $this->authorize('view', $patient);
        abort_unless(MotherChild::canRecord($request->user()), 403, 'Your role cannot do that.');
    }

    private function visit(?string $uuid, Patient $patient): ?Visit
    {
        return $uuid === null ? null : Visit::query()->where('uuid', $uuid)->where('patient_id', $patient->id)->firstOrFail();
    }

    /** @return array<string, list<string>> */
    private function pregnancyRules(): array
    {
        return [
            'lmp' => ['nullable', 'date'],
            'edd' => ['nullable', 'date'],
            'gravida' => ['sometimes', 'integer'],
            'para' => ['sometimes', 'nullable', 'integer'],
            'risk_factors' => ['sometimes', 'array', 'max:20'],
            'risk_factors.*' => ['string', 'max:30'],
            'risk_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:120'],
            'birth_companion' => ['sometimes', 'nullable', 'string', 'max:120'],
            'transport' => ['sometimes', 'nullable', 'string', 'max:120'],
            'blood_donor' => ['sometimes', 'nullable', 'string', 'max:120'],
            'birth_plan_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string,mixed> */
    private function pregnancyRow(Pregnancy $p, bool $withSchedule): array
    {
        $row = [
            'uuid' => $p->uuid,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'lmp' => $p->lmp?->toDateString(),
            'edd' => $p->edd->toDateString(),
            'edd_from_lmp' => $p->edd_from_lmp,
            'gravida' => $p->gravida,
            'para' => $p->para,
            'risk_factors' => (array) $p->risk_factors,
            'risk_notes' => $p->risk_notes,
            'birth_plan' => [
                'place' => $p->birth_place, 'companion' => $p->birth_companion, 'transport' => $p->transport,
                'blood_donor' => $p->blood_donor, 'notes' => $p->birth_plan_notes,
            ],
            'ended_on' => $p->ended_on?->toDateString(),
            'outcome_notes' => $p->outcome_notes,
            'contacts' => $p->contacts->map(fn (AncContact $c) => $this->contactRow($c))->values(),
        ];
        if ($withSchedule) {
            $row['schedule'] = $this->pregnancies->schedule($p);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function contactRow(AncContact $c): array
    {
        return [
            'uuid' => $c->uuid, 'seen_on' => $c->seen_on->toDateString(), 'contact_no' => $c->contact_no,
            'gestation_days' => $c->gestation_days, 'gestation_label' => PregnancyService::gestationLabel($c->gestation_days),
            'blood_pressure' => $c->blood_pressure, 'weight' => $c->weight, 'fundal_height' => $c->fundal_height,
            'fetal_heart_rate' => $c->fetal_heart_rate, 'checks' => (array) $c->checks, 'notes' => $c->notes,
            'recorded_by' => $c->relationLoaded('recordedBy') ? $c->recordedBy?->name : null,
        ];
    }
}
