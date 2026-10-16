<?php

namespace App\Services;

use App\Models\DispensationItem;
use App\Models\DoseItem;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\StockItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The pharmacist's queue and the doctor's "repeat last prescription".
 *
 * A prescription line is UNDISPENSED when nothing on its visit was dispensed
 * under its drug's name, and a prescription is PENDING while any line is. If a
 * dispensation was recorded against the prescription itself (the web and app
 * pass `prescription_id`), the pharmacist has dealt with all of it - a
 * substituted brand must not leave it in the queue for ever.
 *
 * Bounded: the newest CAP prescriptions inside WINDOW_DAYS are considered.
 */
class DispenseQueueService
{
    public const WINDOW_DAYS = 14;

    public const CAP = 300;

    /**
     * @return Collection<int,array<string,mixed>> oldest first
     */
    public function pending(): Collection
    {
        $prescriptions = Prescription::query()
            ->with(['visit:id,uuid,patient_id', 'visit.patient:id,uuid,first_name,last_name,patient_no', 'doseItems'])
            ->whereHas('doseItems')
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereDoesntHave('visit', fn ($q) => $q->where('outcome', 'cancelled'))
            ->orderByDesc('created_at')->limit(self::CAP)
            ->get();

        if ($prescriptions->isEmpty()) {
            return collect();
        }

        $handled = \App\Models\Dispensation::query()->whereIn('prescription_id', $prescriptions->pluck('id'))
            ->pluck('prescription_id')->flip();

        $byVisit = DispensationItem::query()
            ->join('dispensations', 'dispensations.id', '=', 'dispensation_items.dispensation_id')
            ->whereNull('dispensations.deleted_at')
            ->whereIn('dispensations.visit_id', $prescriptions->pluck('visit_id')->unique())
            ->get(['dispensations.visit_id as visit_id', 'dispensation_items.name as name'])
            ->groupBy('visit_id')
            ->map(fn ($rows) => $rows->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->flip());

        $open = [];
        foreach ($prescriptions as $p) {
            if ($handled->has($p->id)) {
                continue;
            }
            $given = $byVisit->get($p->visit_id, collect());
            $lines = $p->doseItems->filter(fn (DoseItem $d) => ! $given->has(mb_strtolower(trim($d->drug_name))))->values();
            if ($lines->isNotEmpty()) {
                $open[] = [$p, $lines];
            }
        }

        $stock = $this->stockFor(collect($open)->flatMap(fn ($o) => $o[1])->pluck('drug_name')->unique()->values()->all());
        $now = now();

        return collect($open)->map(fn ($o) => $this->row($o[0], $o[1], $stock, $now))->sortByDesc('waited_minutes')->values();
    }

    /**
     * @param  Collection<int,DoseItem>  $lines
     * @param  array<string,StockItem>  $stock
     * @return array<string,mixed>
     */
    private function row(Prescription $p, Collection $lines, array $stock, Carbon $now): array
    {
        return [
            'uuid' => $p->uuid,
            'visit_uuid' => $p->visit?->uuid,
            'patient' => [
                'uuid' => $p->visit?->patient?->uuid,
                'name' => $p->visit?->patient?->full_name,
                'patient_no' => $p->visit?->patient?->patient_no,
                // What the pharmacist must see before handing anything over.
                'alerts' => \App\Support\Clinical\PatientAlerts::forPayload($p->visit?->patient),
            ],
            'prescribed_at' => $p->created_at?->toIso8601String(),
            'waited_minutes' => max(0, (int) $p->created_at->diffInMinutes($now)),
            'items' => $lines->map(fn (DoseItem $d) => $this->line($d, $stock[mb_strtolower(trim($d->drug_name))] ?? null))->all(),
        ];
    }

    /** @return array<string,mixed>|null */
    public function lastPrescription(Patient $patient, ?int $excludeVisitId = null): ?array
    {
        $p = Prescription::query()->with(['doseItems', 'visit:id,uuid', 'prescriber:id,name'])
            ->where('patient_id', $patient->id)->whereHas('doseItems')
            ->when($excludeVisitId !== null, fn ($q) => $q->where('visit_id', '!=', $excludeVisitId))
            ->latest('created_at')->latest('id')->first();

        if ($p === null) {
            return null;
        }

        return [
            'uuid' => $p->uuid,
            'visit_uuid' => $p->visit?->uuid,
            'prescribed_by' => $p->prescriber?->name,
            'prescribed_at' => $p->created_at?->toIso8601String(),
            'notes' => $p->notes,
            'items' => $p->doseItems->map(fn (DoseItem $d) => [
                'drug_name' => $d->drug_name,
                'dose' => $d->dosage,
                'dosage' => $d->dosage,
                'slots' => array_values((array) $d->slots),
                'days' => (int) $d->days,
                'instructions' => $d->instructions,
            ])->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function line(DoseItem $d, ?StockItem $stock): array
    {
        $qty = count((array) $d->slots) * max(1, (int) $d->days);
        $available = $stock === null ? null : (string) $stock->current_quantity;

        return [
            'drug_name' => $d->drug_name,
            'qty' => $qty,
            'dose' => $d->dosage,
            'instructions' => $d->instructions,
            'stock_item_uuid' => $stock?->uuid,
            'stock_available' => $available,
            'short' => $available !== null && bccomp($available, (string) $qty, 2) < 0,
        ];
    }

    /**
     * The shelf item each drug name stands for: an exact (case-insensitive)
     * name, else the first active item whose name starts with it.
     *
     * @param  list<string>  $names
     * @return array<string,StockItem>
     */
    private function stockFor(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $lower = array_map(fn ($n) => mb_strtolower(trim($n)), $names);
        $found = [];

        foreach (StockItem::query()->where('is_active', true)->whereIn(DB::raw('lower(name)'), $lower)->orderByDesc('current_quantity')->get() as $item) {
            $found[mb_strtolower(trim($item->name))] ??= $item;
        }

        foreach (array_slice(array_diff($lower, array_keys($found)), 0, 40) as $name) {
            $item = StockItem::query()->where('is_active', true)->where('name', 'like', $this->escapeLike($name).'%')
                ->orderByDesc('current_quantity')->first();
            if ($item !== null) {
                $found[$name] = $item;
            }
        }

        return $found;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
