<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\FollowUpService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Follow-up for the phone (docs/api/follow-up.md): the review a visit
 * suggests and its booking, and the receptionist's list of recalls and
 * reviews due with a text to send from it. FollowUpService decides all of it.
 */
class FollowUpController extends Controller
{
    public function __construct(private readonly FollowUpService $followUps) {}

    /** GET visits/{visit}/review: the suggested date and the reviews already booked from this visit. */
    public function suggestion(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        abort_unless(request()->user()?->can('appointments.view'), 403);

        return ApiResponse::success([
            'suggestion' => $this->followUps->suggestion($visit),
            'booked' => $this->followUps->reviewsFor($visit)->map(fn (Appointment $a) => $this->appointmentRow($a))->values(),
        ]);
    }

    /** POST visits/{visit}/review {date, time, doctor_user_id?} */
    public function book(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        abort_unless($request->user()?->can('appointments.manage'), 403);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'doctor_user_id' => ['nullable', 'integer'],
        ]);

        $doctor = null;
        if (filled($data['doctor_user_id'] ?? null)) {
            // A doctor of this hospital only (User carries no tenant scope).
            $doctor = \App\Models\User::query()->currentHospital()->whereKey((int) $data['doctor_user_id'])->value('id');
            abort_if($doctor === null, 422, 'That doctor is not one of this clinic\'s.');
        }

        try {
            $appointment = $this->followUps->bookReview($visit, $data['date'], $data['time'], $doctor !== null ? (int) $doctor : null, $request->user()->id);
        } catch (SlotUnavailableException|\RuntimeException $e) {
            return ApiResponse::error(\App\Enums\ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['time' => [$e->getMessage()]]);
        }

        // Asked again for the same time: the booking already made, 200 not 201.
        return $appointment->wasRecentlyCreated
            ? ApiResponse::success($this->appointmentRow($appointment->load('doctor:id,name')), 'Review booked.', 201)
            : ApiResponse::success($this->appointmentRow($appointment->load('doctor:id,name')), 'This review was already booked.');
    }

    /** GET follow-ups?show=week|missed|recalls */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('appointments.view'), 403);

        $show = (string) $request->query('show', 'week');
        $show = isset(FollowUpService::LISTS[$show]) ? $show : 'week';
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $page = match ($show) {
            'missed' => $this->followUps->missedReviews()->paginate($perPage),
            'recalls' => $this->followUps->recallsDue()->paginate($perPage),
            default => $this->followUps->reviewsThisWeek()->paginate($perPage),
        };

        $rows = collect($page->items())->map(fn ($row) => $row instanceof Patient
            ? ['kind' => 'recall', 'id' => $row->id, 'key' => 'recall:'.$row->next_recall_on?->toDateString(), 'due_on' => $row->next_recall_on?->toDateString(), 'patient' => $this->patientRow($row), 'appointment' => null]
            : ['kind' => 'review', 'id' => $row->id, 'key' => 'review:'.$row->uuid, 'due_on' => $row->scheduled_at->toDateString(), 'patient' => $row->patient ? $this->patientRow($row->patient) : null, 'appointment' => $this->appointmentRow($row)]);

        return ApiResponse::paginated($page, $rows->values()->all(), ['show' => $show, 'counts' => $this->followUps->counts()]);
    }

    /** POST follow-ups/text {kind: review|recall, id} */
    public function text(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('appointments.manage'), 403);
        $data = $request->validate(['kind' => ['required', 'in:review,recall'], 'id' => ['required', 'integer']]);

        // Through the tenant scope: another clinic's id is a 404.
        if ($data['kind'] === 'recall') {
            $patient = Patient::query()->findOrFail((int) $data['id']);
            if (! $this->followUps->recallDue($patient)) {
                return ApiResponse::error(\App\Enums\ApiErrorCode::ValidationFailed, 'No dental check-up is due for this patient.', 422, ['id' => ['No dental check-up is due for this patient.']]);
            }
            $key = 'recall:'.$patient->next_recall_on?->toDateString();
        } else {
            $appointment = Appointment::query()->with('patient')->whereNotNull('origin_visit_id')->findOrFail((int) $data['id']);
            $patient = $appointment->patient ?? abort(404);
            $key = 'review:'.$appointment->uuid;
        }

        $result = $this->followUps->remind($patient, $key);

        return ApiResponse::success(['sent' => $result['sent']], $result['message']);
    }

    /** @return array<string,mixed> */
    private function appointmentRow(Appointment $a): array
    {
        return [
            'uuid' => $a->uuid,
            'scheduled_at' => $a->scheduled_at->toIso8601String(),
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'doctor' => $a->doctor?->name,
            'reason' => $a->reason,
        ];
    }

    /** @return array<string,mixed> */
    private function patientRow(Patient $p): array
    {
        return [
            'uuid' => $p->uuid,
            'name' => $p->full_name,
            'patient_no' => $p->patient_no,
            'phone' => $p->phone_1 ?: $p->phone_2,
        ];
    }
}
