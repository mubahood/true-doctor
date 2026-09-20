<?php

namespace App\Livewire\Visits\Panels;

use App\Enums\DoseRecordStatus;
use App\Enums\DoseSlot;
use App\Http\Requests\DoseAdministerRequest;
use App\Http\Requests\PrescriptionRequest;
use App\Models\DoseItemRecord;
use App\Models\Prescription;
use App\Services\PrescriptionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Prescribing and dose administration. Writing a prescription is a real
 * repeater (plan D2 — the classic page hardcoded a single `items[0][…]` line):
 * add a row per drug, tick its daily slots, and PrescriptionService expands
 * every row into its administration schedule inside one transaction.
 *
 * Two distinct abilities live here, exactly as the old page had them: doctors
 * hold prescriptions.prescribe and write the script; nurses hold
 * prescriptions.administer and mark each scheduled dose given or missed.
 */
#[Lazy]
class Prescriptions extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    public bool $showForm = false;

    public ?string $notes = null;

    /**
     * Repeater rows: {uid, drug_name, dosage, slots[], days, start_date,
     * instructions}. `uid` keys the row in the DOM and carries no rule. Typed
     * loosely on purpose — the client owns this array, so every read is guarded
     * and PrescriptionRequest::rulesFor() is the gate before it reaches the
     * service.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $items = [];

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;

        // Anyone who may read the visit may read its prescriptions; the
        // write actions carry their own ability.
        $this->authorize('view', $this->visit);

        $this->items = [self::blankRow()];
    }

    /** @return array{uid: string, drug_name: string, dosage: null, slots: array<int, string>, days: int, start_date: string, instructions: null} */
    private static function blankRow(): array
    {
        return [
            'uid' => (string) Str::uuid(),
            'drug_name' => '',
            'dosage' => null,
            'slots' => [],
            'days' => 3,
            'start_date' => Carbon::now()->toDateString(),
            'instructions' => null,
        ];
    }

    // ── Repeater ───────────────────────────────────────────────

    public function openForm(): void
    {
        $this->authorize('prescribe', Prescription::class);

        $this->items = [self::blankRow()];
        $this->notes = null;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function addRow(): void
    {
        $this->authorize('prescribe', Prescription::class);

        $this->items[] = self::blankRow();
    }

    public function removeRow(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if ($this->items === []) {
            $this->items = [self::blankRow()];
        }

        $this->resetErrorBag();
    }

    // ── Data ───────────────────────────────────────────────────

    /** @return Collection<int, Prescription> */
    #[Computed]
    public function prescriptions(): Collection
    {
        return $this->visit->prescriptions()
            ->with(['prescriber', 'doseItems.records.administeredBy'])
            ->latest()
            ->get();
    }

    /** @return array<string, string> */
    #[Computed]
    public function slots(): array
    {
        return DoseSlot::options();
    }

    // ── Write a prescription ───────────────────────────────────

    public function prescribe(PrescriptionService $service): void
    {
        $visit = $this->visit;
        $this->authorize('view', $visit);
        $this->authorize('prescribe', Prescription::class);

        $this->normaliseRows();

        $data = $this->validate(PrescriptionRequest::rulesFor());

        $items = array_map(fn (array $row) => [
            'drug_name' => (string) $row['drug_name'],
            'dosage' => $row['dosage'] ?? null,
            'slots' => array_values($row['slots']),
            'days' => (int) $row['days'],
            'start_date' => $row['start_date'] ?? null,
            'instructions' => $row['instructions'] ?? null,
        ], array_values($data['items']));

        $service->prescribe($visit, $items, $data['notes'] ?? null, Auth::id());

        $this->showForm = false;
        $this->items = [self::blankRow()];
        $this->notes = null;
        unset($this->visit, $this->prescriptions);

        $this->dispatch('toast', message: 'Prescription added.', type: 'success');
        $this->dispatch('visit-updated');
    }

    /** Blank optionals must be null before validation (no ConvertEmptyStringsToNull on the wire). */
    private function normaliseRows(): void
    {
        if (is_string($this->notes) && trim($this->notes) === '') {
            $this->notes = null;
        }

        foreach ($this->items as $index => $row) {
            foreach (['dosage', 'start_date', 'instructions'] as $field) {
                $value = $row[$field] ?? null;
                if (is_string($value) && trim($value) === '') {
                    $this->items[$index][$field] = null;
                }
            }
            $slots = $row['slots'] ?? null;
            $this->items[$index]['slots'] = array_values(array_filter(
                is_array($slots) ? $slots : [],
                fn ($slot) => is_string($slot) && $slot !== '',
            ));
        }
    }

    // ── Dose administration ────────────────────────────────────

    public function markDose(int $recordId, string $status, PrescriptionService $service): void
    {
        $this->authorize('administer', Prescription::class);

        $validated = Validator::make(
            ['status' => $status],
            ['status' => DoseAdministerRequest::rulesFor()['status']],
        )->validate();

        // The record must belong to *this* visit (and, through the global
        // scope, to this tenant) — a hand-crafted id from elsewhere 404s.
        /** @var DoseItemRecord $record */
        $record = DoseItemRecord::query()
            ->whereKey($recordId)
            ->whereHas('doseItem.prescription', fn ($q) => $q->where('visit_id', $this->visitId))
            ->firstOrFail();

        $service->markDose($record, DoseRecordStatus::from($validated['status']), Auth::id());

        unset($this->prescriptions);

        $this->dispatch('toast', message: 'Dose updated.', type: 'success');
    }

    public function render()
    {
        $this->authorize('view', $this->visit);

        return view('livewire.visits.panels.prescriptions');
    }
}
