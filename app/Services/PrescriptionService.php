<?php

namespace App\Services;

use App\Enums\DoseRecordStatus;
use App\Enums\DoseSlot;
use App\Models\Dispensation;
use App\Models\DoseItem;
use App\Models\DoseItemRecord;
use App\Models\Prescription;
use App\Models\StockItem;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes a prescription (header + dose items) for a visit and expands
 * each dose item into its administration schedule via DosageScheduleGenerator —
 * all in one transaction so a prescription and its schedule are never partial.
 */
class PrescriptionService
{
    public function __construct(private readonly DosageScheduleGenerator $generator) {}

    /**
     * A line with `schedule` false keeps its slots (they size what the
     * pharmacy hands over) but gets no administration schedule: the dosage
     * section's medicine is taken at home, not given on a ward round.
     *
     * @param  list<array{drug_name:string,dosage?:string|null,slots:array<int,string>,days:int,start_date?:string|null,instructions?:string|null,stock_item_id?:int|null,frequency?:string|null,schedule?:bool}>  $items
     */
    public function prescribe(Visit $visit, array $items, ?string $notes, ?int $by = null): Prescription
    {
        return DB::transaction(function () use ($visit, $items, $notes, $by) {
            $prescription = Prescription::create([
                'uuid' => (string) Str::uuid(),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'prescribed_by' => $by,
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                $dose = $prescription->doseItems()->create([
                    'stock_item_id' => $item['stock_item_id'] ?? null,
                    'drug_name' => $item['drug_name'],
                    'dosage' => $item['dosage'] ?? null,
                    'frequency' => $item['frequency'] ?? null,
                    'slots' => $item['slots'],
                    'days' => (int) $item['days'],
                    'start_date' => $item['start_date'] ?? Carbon::now()->toDateString(),
                    'instructions' => $item['instructions'] ?? null,
                ]);

                if ($item['schedule'] ?? true) {
                    $this->generator->generate($dose);
                }
            }

            return $prescription;
        });
    }

    /**
     * The dosage section of a visit (docs/pharmacy.md, "Dosage"): rows of a
     * medicine picked from the clinic's own stock, the dose, how often, for
     * how many days and a note. Written as one prescription, so dispensing
     * and billing follow the prescription flow: nothing is charged here.
     *
     * @param  list<array{stock_item_id:int,dose:string,frequency:string,days:int,note?:string|null}>  $rows
     */
    public function writeDosage(Visit $visit, array $rows, ?int $by = null): Prescription
    {
        if ($rows === []) {
            throw new \DomainException('Add at least one medicine.');
        }

        // Tenant-scoped: a medicine of another hospital is not found.
        $medicines = StockItem::query()->whereIn('id', array_map(fn (array $r) => (int) $r['stock_item_id'], $rows))->get()->keyBy('id');

        $items = [];
        foreach ($rows as $row) {
            $medicine = $medicines->get((int) $row['stock_item_id']);
            if ($medicine === null) {
                throw new \DomainException('That medicine is not in your stock list.');
            }
            $frequency = trim((string) $row['frequency']);
            $items[] = [
                'stock_item_id' => $medicine->id,
                'drug_name' => (string) $medicine->name,
                'dosage' => trim((string) $row['dose']),
                'frequency' => $frequency,
                'slots' => self::slotsFor($frequency),
                'days' => (int) $row['days'],
                'start_date' => Carbon::now()->toDateString(),
                'instructions' => filled($row['note'] ?? null) ? trim((string) $row['note']) : null,
                'schedule' => false,
            ];
        }

        return $this->prescribe($visit, $items, null, $by);
    }

    /**
     * Remove one dosage row. Refused once the pharmacy has dispensed against
     * its prescription or a dose of it was given: what happened stays on record.
     */
    public function removeDose(DoseItem $item): void
    {
        DB::transaction(function () use ($item) {
            /** @var Prescription $prescription */
            $prescription = Prescription::query()->whereKey($item->prescription_id)->lockForUpdate()->firstOrFail();

            if (Dispensation::query()->where('prescription_id', $prescription->id)->exists()) {
                throw new \DomainException('This medicine has been dispensed, so it stays on the visit.');
            }
            if ($item->records()->where('status', '!=', DoseRecordStatus::Pending->value)->exists()) {
                throw new \DomainException('A dose of this medicine has been recorded, so it stays on the visit.');
            }

            $item->records()->delete();
            $item->delete();

            if (! $prescription->doseItems()->exists()) {
                $prescription->delete();
            }
        });
    }

    /**
     * The daily slots a "how often" stands for, so the pharmacy's queue can
     * size the quantity. "When needed" and free text have none.
     *
     * @return list<string>
     */
    public static function slotsFor(string $frequency): array
    {
        return match (mb_strtolower(trim($frequency))) {
            'once a day' => [DoseSlot::Morning->value],
            'twice a day' => [DoseSlot::Morning->value, DoseSlot::Evening->value],
            'three times a day' => [DoseSlot::Morning->value, DoseSlot::Afternoon->value, DoseSlot::Night->value],
            'four times a day' => array_map(fn (DoseSlot $s) => $s->value, DoseSlot::ordered()),
            default => [],
        };
    }

    /** Nurse marks one scheduled dose administered or missed. */
    public function markDose(DoseItemRecord $record, DoseRecordStatus $status, ?int $by = null, ?string $note = null): DoseItemRecord
    {
        $record->status = $status;
        $record->note = $note;
        $record->administered_at = $status === DoseRecordStatus::Administered ? Carbon::now() : null;
        $record->administered_by = $status === DoseRecordStatus::Administered ? $by : null;
        $record->save();

        return $record;
    }
}
