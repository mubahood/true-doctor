<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\FollowUpService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the visit page offers that only its Livewire panels could answer
 * (docs/api/visit-page.md): the free times for the review booked from a
 * visit (the web's Book a review and the case sheet's Follow-up) and the
 * medicines a dosage is written from (the Dosage panel's picker). The
 * printed visit report and prescription are the web's own controllers.
 */
class VisitPageController extends Controller
{
    /** Shown before anyone types, and the most a search answers: the web picker's own cap. */
    private const MEDICINES = 20;

    /** GET visits/{visit}/review/times?date=Y-m-d */
    public function reviewTimes(Request $request, Visit $visit, FollowUpService $followUps): JsonResponse
    {
        $this->authorize('view', $visit);
        // Whoever books the review (both web panels need it to offer a time).
        abort_unless($request->user()?->can('appointments.manage'), 403, 'Your role cannot do that.');

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ], ['date.after_or_equal' => 'Choose today or a later day.']);

        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($followUps->reviewTimes($visit, $user, $data['date'] ?? null));
    }

    /** GET dosage/medicines?q= */
    public function medicines(Request $request): JsonResponse
    {
        // Whoever writes a dosage (DosageController::store).
        $this->authorize('prescribe', Prescription::class);

        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        $rows = StockItem::query()->medicineChoices($q)->limit(self::MEDICINES)
            ->get(['id', 'uuid', 'name', 'sku', 'unit', 'current_quantity'])
            ->map(function (StockItem $s) {
                $left = bccomp((string) $s->current_quantity, '0', 2) > 0;
                $quantity = (string) $s->current_quantity;
                $quantity = str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;

                return [
                    'id' => (int) $s->id,
                    'uuid' => $s->uuid,
                    'name' => (string) $s->name,
                    'sku' => $s->sku,
                    'unit' => $s->unit,
                    'on_hand' => (string) $s->current_quantity,
                    'in_stock' => $left,
                    // The words the web's picker shows under the name.
                    'meta' => $left ? trim($quantity.' '.$s->unit).' in stock' : 'Out of stock',
                ];
            });

        return ApiResponse::success($rows->values());
    }
}
