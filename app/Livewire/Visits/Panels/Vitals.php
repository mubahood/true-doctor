<?php

namespace App\Livewire\Visits\Panels;

use App\Http\Requests\VisitVitalsRequest;
use App\Services\VisitService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Triage vitals for one visit. The seven fields bind with wire:model.blur so
 * each one validates (and the BMI recomputes) as the nurse leaves it; the save
 * itself is pessimistic — a clinical write only lands when the server says so.
 *
 * BMI is never accepted from the client: VisitService computes and stores
 * it, and the live preview asks that same service so there is one formula.
 * Anyone who may view the visit but not record vitals gets the read-only
 * table instead of the form.
 *
 * @property-read float|null $bmi
 * @property-read array<string, list<array{value:string,label:string,last:bool}>> $suggestions
 */
#[Lazy]
class Vitals extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    private const FIELDS = ['temperature', 'blood_pressure', 'weight', 'height', 'pulse', 'spo2', 'respiratory_rate'];

    public ?string $temperature = null;

    public ?string $blood_pressure = null;

    public ?string $weight = null;

    public ?string $height = null;

    public ?string $pulse = null;

    public ?string $spo2 = null;

    public ?string $respiratory_rate = null;

    /**
     * Vitals are read far more often than they are written, so the panel shows
     * them and the form opens from a button. Seven number fields sitting open
     * on the page made the visit look like something to fill in.
     */
    public bool $showForm = false;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;

        // Resolves the visit through the tenant scope (404 cross-tenant).
        $this->authorize('view', $this->visit);

        foreach (self::FIELDS as $field) {
            $value = $this->visit->{$field};
            $this->{$field} = $value === null ? null : (string) $value;
        }
    }

    /**
     * Values worth offering under each field.
     *
     * Two kinds, and the difference matters. The first is **this patient's own
     * last reading** — a real datum, labelled as one. It is the useful one: an
     * adult's height does not change between visits, and a nurse should not
     * have to fetch a tape measure to record what the hospital already knows.
     *
     * The rest are common readings, and they are deliberately SPREAD across
     * the clinical range rather than clustered on "normal" — 36.5 · 37.5 ·
     * 38.5 · 39.5, not four ways of saying afebrile. A pill that offers only
     * the healthy value invites someone to accept it without measuring, which
     * is how a record ends up saying something nobody observed. Spread, the
     * click is a choice between real alternatives.
     *
     * Weight has none. A guessed weight is worse than a blank one: it is
     * dosing information.
     *
     * @return array<string, list<array{value:string,label:string,last:bool}>>
     */
    #[Computed]
    public function suggestions(): array
    {
        $common = [
            'temperature' => ['36.5', '37.5', '38.5', '39.5'],
            'blood_pressure' => ['110/70', '120/80', '140/90', '160/100'],
            'weight' => [],
            'height' => [],
            'pulse' => ['60', '80', '100', '120'],
            'spo2' => ['99', '96', '92', '88'],
            'respiratory_rate' => ['16', '20', '24', '30'],
        ];

        $previous = $this->previousVitals();

        $out = [];
        foreach (self::FIELDS as $field) {
            $rows = [];

            $last = $previous[$field] ?? null;
            if ($last !== null && trim((string) $last) !== '') {
                $rows[] = ['value' => (string) $last, 'label' => 'Last '.$last, 'last' => true];
            }

            foreach ($common[$field] as $value) {
                if ($value !== ($last === null ? null : (string) $last)) {
                    $rows[] = ['value' => $value, 'label' => $value, 'last' => false];
                }
            }

            $out[$field] = $rows;
        }

        return $out;
    }

    /**
     * What was measured for this patient last time, on any earlier visit.
     *
     * @return array<string, string|null>
     */
    private function previousVitals(): array
    {
        $visit = $this->visit;

        /** @var \App\Models\Visit|null $earlier */
        $earlier = \App\Models\Visit::where('patient_id', $visit->patient_id)
            ->whereKeyNot($visit->id)
            ->whereNotNull('vitals_recorded_at')
            ->latest('vitals_recorded_at')
            ->latest('id')
            ->first();

        if ($earlier === null) {
            return [];
        }

        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $earlier->{$field};
            // Trailing noughts are how a column prints, not how anyone writes
            // a measurement: 68.50 kg is 68.5.
            $out[$field] = $value === null ? null : $this->tidy((string) $value);
        }

        return $out;
    }

    private function tidy(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /**
     * Fill a field from its own pills, and nothing else.
     *
     * Whitelisted twice over: the field must be one of the seven, and the
     * value must be one this field actually offered.
     */
    public function useVital(string $field, string $value): void
    {
        $this->authorize('recordVitals', $this->visit);

        if (! in_array($field, self::FIELDS, true)) {
            return;
        }

        $offered = array_column($this->suggestions[$field] ?? [], 'value');
        if (! in_array($value, $offered, true)) {
            return;
        }

        $this->{$field} = $value;
        unset($this->bmi);
    }

    /** Live BMI preview from the values currently in the form (never persisted here). */
    #[Computed]
    public function bmi(): ?float
    {
        return app(VisitService::class)->computeBmi(
            is_numeric($this->weight) ? (float) $this->weight : null,
            is_numeric($this->height) ? (float) $this->height : null,
        );
    }

    public function openForm(): void
    {
        $this->authorize('recordVitals', $this->visit);
        $this->resetErrorBag();
        unset($this->suggestions);
        $this->showForm = true;
    }

    public function save(VisitService $service): void
    {
        $visit = $this->visit;
        $this->authorize('recordVitals', $visit);

        // There is no ConvertEmptyStringsToNull on the Livewire wire: a cleared
        // number input arrives as '' and would fail `numeric` despite `nullable`.
        foreach (self::FIELDS as $field) {
            if (is_string($this->{$field}) && trim($this->{$field}) === '') {
                $this->{$field} = null;
            }
        }

        $data = $this->validate(VisitVitalsRequest::rulesFor());

        $service->recordVitals($visit, $data);

        unset($this->visit);

        $this->showForm = false;

        $this->dispatch('toast', message: 'Vitals recorded.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function render()
    {
        $this->authorize('view', $this->visit);

        return view('livewire.visits.panels.vitals');
    }
}
