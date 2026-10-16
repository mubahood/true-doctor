<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\BedStatus;
use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedTransfer;
use App\Models\Ward;
use App\Services\WardService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The occupancy board (admin.admissions.board): every bed, who is in it, the
 * hospital's figures, one bed's quick view, and taking a bed out of service.
 * Reading needs ipd.view (AdmissionPolicy::viewAny, as the page); the bed
 * status change needs what the page asks, BedPolicy::update (wards.manage).
 * Admit / transfer / discharge are POST /admissions, .../transfer, .../discharge.
 */
class OccupancyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Admission::class);

        $f = $request->validate([
            'ward' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(array_column(BedStatus::cases(), 'value'))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $term = trim((string) ($f['q'] ?? ''));
        $status = $f['status'] ?? '';

        $narrow = fn (Builder $q) => $q
            ->when($status !== '', fn (Builder $x) => $x->where('status', $status))
            ->when($term !== '', fn (Builder $x) => $x->where(fn (Builder $y) => $y
                ->where('name', 'like', "%{$term}%")
                ->orWhereHas('currentAdmission.patient', fn (Builder $p) => $p
                    ->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%")->orWhere('patient_no', 'like', "%{$term}%"))));

        $wards = Ward::query()
            ->with([
                'beds' => fn ($q) => $narrow($q->getQuery())->orderBy('name'),
                'beds.currentAdmission.patient', 'beds.currentAdmission.admittingDoctor',
            ])
            ->where('is_active', true)
            ->when(filled($f['ward'] ?? null), fn (Builder $q) => $q->whereKey((int) $f['ward']))
            ->when($status !== '' || $term !== '', fn (Builder $q) => $q->whereHas('beds', fn (Builder $b) => $narrow($b)))
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            // The whole hospital, not the filtered view.
            'figures' => $this->figures(),
            'wards' => $wards->map(fn (Ward $w) => [
                'id' => $w->id, 'name' => $w->name,
                'beds' => $w->beds->map(fn (Bed $b) => $this->bed($b))->values(),
            ])->values(),
        ]);
    }

    /** One bed over the board: the stay so far, the last moves, or who left it last. */
    public function show(Bed $bed): JsonResponse
    {
        $this->authorize('viewAny', Admission::class);

        $bed->load(['ward', 'currentAdmission.patient', 'currentAdmission.admittingDoctor', 'currentAdmission.visit']);
        $adm = $bed->currentAdmission;

        $transfers = $adm === null ? collect() : BedTransfer::with(['fromBed', 'toBed'])
            ->where('admission_id', $adm->id)->latest('id')->limit(5)->get();

        $last = $adm !== null ? null : Admission::with('patient')->where('bed_id', $bed->id)
            ->whereNotNull('discharged_at')->latest('discharged_at')->first();

        return ApiResponse::success($this->bed($bed) + [
            'ward' => $bed->ward === null ? null : ['id' => $bed->ward->id, 'name' => $bed->ward->name],
            'recent_transfers' => $transfers->map(fn ($t) => [
                'at' => $t->created_at?->toIso8601String(), 'from' => $t->fromBed?->name, 'to' => $t->toBed?->name, 'reason' => $t->reason,
            ])->values(),
            'last_stay' => $last === null ? null : [
                'patient_name' => $last->patient?->full_name, 'discharged_at' => $last->discharged_at->toIso8601String(), 'status' => $last->status->value,
            ],
        ]);
    }

    /** Take a bed out of service or put it back: status available | maintenance. */
    public function status(Request $request, Bed $bed, WardService $wards): JsonResponse
    {
        $this->authorize('update', $bed);

        $data = $request->validate(['status' => ['required', Rule::in(array_column(BedStatus::cases(), 'value'))]]);
        $to = BedStatus::from($data['status']);

        try {
            $wards->setBedStatus($bed, $to);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['status' => [$e->getMessage()]]);
        }

        return ApiResponse::success(['id' => $bed->id, 'status' => $to->value], $bed->name.' is now '.strtolower($to->label()).'.');
    }

    /** @return array<string,int> */
    private function figures(): array
    {
        $beds = Bed::query()->whereHas('ward', fn (Builder $q) => $q->where('is_active', true));
        $total = (clone $beds)->count();
        $occupied = (clone $beds)->where('status', BedStatus::Occupied)->count();

        return [
            'total' => $total, 'occupied' => $occupied,
            'free' => (clone $beds)->where('status', BedStatus::Available)->count(),
            'maintenance' => (clone $beds)->where('status', BedStatus::Maintenance)->count(),
            'percent' => $total === 0 ? 0 : (int) round($occupied / $total * 100),
        ];
    }

    /** @return array<string,mixed> */
    private function bed(Bed $bed): array
    {
        $adm = $bed->currentAdmission;

        return [
            'id' => $bed->id, 'name' => $bed->name, 'ward_id' => $bed->ward_id,
            'status' => $bed->status->value, 'status_label' => $bed->status->label(),
            'daily_charge' => (string) $bed->daily_charge,
            'occupant' => $adm === null ? null : [
                'admission_uuid' => $adm->uuid,
                'patient' => ['uuid' => $adm->patient?->uuid, 'name' => $adm->patient?->full_name, 'patient_no' => $adm->patient?->patient_no],
                'admitting_doctor' => $adm->admittingDoctor?->name,
                'reason' => $adm->reason,
                'admitted_at' => $adm->admitted_at->toIso8601String(),
                'nights' => $adm->nights(),
                'accrued_so_far' => bcmul((string) $bed->daily_charge, (string) $adm->nights(), 2),
                'visit_uuid' => $adm->visit?->uuid,
            ],
        ];
    }
}
