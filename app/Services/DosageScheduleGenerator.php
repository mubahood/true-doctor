<?php

namespace App\Services;

use App\Enums\DoseRecordStatus;
use App\Enums\DoseSlot;
use App\Models\DoseItem;
use App\Models\DoseItemRecord;
use Illuminate\Support\Carbon;

/**
 * Expands a dose item (which daily slots, for how many days, from a start date)
 * into concrete per-day, per-slot administration records — the legacy's dosing
 * model rebuilt as a deterministic, tested service instead of model save-hooks.
 * Slots are emitted in canonical order (Morning→Night); a record is created once
 * per (item, date, slot) — the unique index makes regeneration idempotent.
 */
class DosageScheduleGenerator
{
    /**
     * @return list<DoseItemRecord> the created records (empty if nothing to schedule)
     */
    public function generate(DoseItem $item): array
    {
        $slots = $this->normaliseSlots($item->slots);
        if ($slots === [] || $item->days < 1) {
            return [];
        }

        $created = [];
        $start = Carbon::parse($item->start_date);

        for ($day = 0; $day < $item->days; $day++) {
            $date = $start->copy()->addDays($day);
            foreach ($slots as $slot) {
                $created[] = DoseItemRecord::create([
                    'dose_item_id' => $item->id,
                    'scheduled_date' => $date->toDateString(),
                    'slot' => $slot,
                    'status' => DoseRecordStatus::Pending,
                ]);
            }
        }

        return $created;
    }

    /**
     * Keep only valid slot values, de-duplicated, in canonical order.
     *
     * @param  array<int,string>  $raw
     * @return list<DoseSlot>
     */
    private function normaliseSlots(array $raw): array
    {
        $wanted = [];
        foreach ($raw as $value) {
            $slot = DoseSlot::tryFrom((string) $value);
            if ($slot !== null) {
                $wanted[$slot->value] = $slot;
            }
        }

        return array_values(array_filter(
            DoseSlot::ordered(),
            fn (DoseSlot $s) => isset($wanted[$s->value]),
        ));
    }
}
