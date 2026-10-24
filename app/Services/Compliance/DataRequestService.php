<?php

namespace App\Services\Compliance;

use App\Enums\DataRequestKind;
use App\Enums\DataRequestStatus;
use App\Models\DataRequest;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Patients' requests about their personal data (docs/compliance/data-subject-rights.md):
 * logged once with the date it is due, closed once with what was done.
 * Nothing is edited or deleted afterwards; the log is the clinic's proof.
 */
class DataRequestService
{
    /** @return array<string, list<mixed>> shared by the screen and anything else that logs one */
    public static function rules(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'patient_id' => ['nullable', 'integer', Rule::exists('patients', 'id')->where('hospital_id', $hospitalId)],
            'requester_name' => ['required', 'string', 'max:150'],
            'requester_contact' => ['nullable', 'string', 'max:150'],
            'kind' => ['required', Rule::enum(DataRequestKind::class)],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The log as the screens list it: open requests first with the soonest
     * due at the top (it is a worklist before it is a log), then the rest.
     * Search is the name of who asked, or the patient's name or number.
     *
     * @param  array{status?:string, kind?:string, search?:string}  $filters
     * @return Builder<DataRequest>
     */
    public function query(array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return DataRequest::query()->with('patient:id,uuid,first_name,last_name,patient_no')
            ->when(($filters['status'] ?? '') !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(($filters['kind'] ?? '') !== '', fn (Builder $q) => $q->where('kind', $filters['kind']))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq
                ->where('requester_name', 'like', "%{$search}%")
                ->orWhereHas('patient', fn (Builder $p) => $p->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")->orWhere('patient_no', 'like', "%{$search}%"))))
            ->orderByRaw('case when status = ? then 0 else 1 end', [DataRequestStatus::Open->value])
            ->orderBy('due_on')->orderByDesc('id');
    }

    /** @param array<string,mixed> $data validated against rules() */
    public function log(array $data, User $by): DataRequest
    {
        $received = Carbon::parse((string) $data['received_on'])->startOfDay();

        $request = DataRequest::create([
            'uuid' => (string) Str::uuid(),
            'patient_id' => $data['patient_id'] ?? null,
            'requester_name' => trim((string) $data['requester_name']),
            'requester_contact' => filled($data['requester_contact'] ?? null) ? trim((string) $data['requester_contact']) : null,
            'kind' => $data['kind'],
            'status' => DataRequestStatus::Open,
            'details' => filled($data['details'] ?? null) ? trim((string) $data['details']) : null,
            'received_on' => $received->toDateString(),
            'due_on' => $received->copy()->addDays((int) config('compliance.response_days', 30))->toDateString(),
            'recorded_by' => $by->id,
        ]);

        activity()->causedBy($by)->performedOn($request)
            ->withProperties(['kind' => $request->kind->value])
            ->log('data request logged');

        return $request;
    }

    /** Answer or refuse an open request. A closed one stays closed. */
    public function close(DataRequest $request, DataRequestStatus $status, string $outcome, User $by): DataRequest
    {
        if ($status === DataRequestStatus::Open) {
            throw new \InvalidArgumentException('A request is closed as answered or refused.');
        }
        $outcome = trim($outcome);
        if ($outcome === '') {
            throw ValidationException::withMessages(['outcome' => $status === DataRequestStatus::Refused
                ? 'Say why the request was refused; the patient is owed the reason.'
                : 'Say what was done, so the log shows how it was answered.']);
        }

        return DB::transaction(function () use ($request, $status, $outcome, $by) {
            /** @var DataRequest $locked */
            $locked = DataRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== DataRequestStatus::Open) {
                throw ValidationException::withMessages(['outcome' => 'This request was already closed.']);
            }

            $locked->update([
                'status' => $status,
                'outcome' => mb_substr($outcome, 0, 2000),
                'closed_at' => now(),
                'closed_by' => $by->id,
            ]);

            activity()->causedBy($by)->performedOn($locked)
                ->withProperties(['status' => $status->value])
                ->log('data request closed');

            return $locked;
        });
    }
}
