<?php

namespace App\Livewire\Concerns;

use App\Http\Requests\DosageRequest;
use App\Models\DoseItem;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The dosage rows a form writes (docs/pharmacy.md, "Dosage"): the visit
 * page's Dosage panel and a dental clinic's new visit form. Each row is a
 * medicine picked from stock (a <livewire:ui.select-search resource="medicines">
 * named "dose-{uid}"), the dose, how often (a choice, or "Other" and the
 * words), for how many days and a note. DosageRequest::rulesFor() is the gate
 * and PrescriptionService::writeDosage() the writer.
 */
trait WritesDosage
{
    /** The "Other" choice of how often: the words are typed instead. */
    public const OTHER = 'other';

    /**
     * Rows: {uid, stock_item_id, dose, frequency, frequency_other, days, note}.
     * The client owns this array, so every read is guarded.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $dosage = [];

    /** @return array<string, mixed> */
    protected static function blankDose(): array
    {
        return [
            'uid' => (string) Str::uuid(),
            'stock_item_id' => null,
            'dose' => '',
            'frequency' => '',
            'frequency_other' => '',
            'days' => 3,
            'note' => '',
        ];
    }

    public function addDoseRow(): void
    {
        $this->dosage[] = self::blankDose();
    }

    public function removeDoseRow(int $index): void
    {
        unset($this->dosage[$index]);
        $this->dosage = array_values($this->dosage);
        $this->resetErrorBag();
    }

    /** @return list<string> the choices of how often */
    public function doseFrequencies(): array
    {
        return DoseItem::FREQUENCIES;
    }

    /** A row's medicine picker reported a pick (or a clear, with null). True when the name was a dosage row's. */
    protected function dosePicked(string $name, ?int $id): bool
    {
        foreach ($this->dosage as $index => $row) {
            if ('dose-'.(string) ($row['uid'] ?? '') === $name) {
                $this->dosage[$index]['stock_item_id'] = $id;

                return true;
            }
        }

        return false;
    }

    /**
     * The rows, checked, in the shape PrescriptionService::writeDosage takes.
     * With $optional, rows left wholly empty are dropped (the new visit form's
     * dosage may be skipped).
     *
     * @return list<array{stock_item_id:int,dose:string,frequency:string,days:int,note:string|null}>
     */
    protected function validatedDosage(bool $optional = false): array
    {
        $rows = [];
        foreach ($this->dosage as $index => $row) {
            $choice = trim((string) ($row['frequency'] ?? ''));
            $frequency = $choice === self::OTHER ? trim((string) ($row['frequency_other'] ?? '')) : $choice;
            $clean = [
                'stock_item_id' => is_numeric($row['stock_item_id'] ?? null) ? (int) $row['stock_item_id'] : null,
                'dose' => trim((string) ($row['dose'] ?? '')),
                'frequency' => $frequency,
                'days' => is_numeric($row['days'] ?? null) ? (int) $row['days'] : null,
                'note' => trim((string) ($row['note'] ?? '')) ?: null,
            ];
            if ($optional && $clean['stock_item_id'] === null && $clean['dose'] === '' && $choice === '' && $clean['note'] === null) {
                continue;
            }
            $rows[$index] = $clean;
        }

        if ($optional && $rows === []) {
            return [];
        }

        $data = Validator::make(
            ['dosage' => $rows],
            DosageRequest::rulesFor('dosage'),
            DosageRequest::messagesFor('dosage') + ['dosage.required' => 'Add at least one medicine.'],
        )->validate();

        return array_values(array_map(fn (array $r) => [
            'stock_item_id' => (int) $r['stock_item_id'],
            'dose' => (string) $r['dose'],
            'frequency' => (string) $r['frequency'],
            'days' => (int) $r['days'],
            'note' => $r['note'] ?? null,
        ], $data['dosage']));
    }
}
