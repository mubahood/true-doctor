<?php

namespace App\Livewire\Patients\Tabs;

use App\Enums\DentalFinding;
use App\Enums\DentalTreatmentStatus;
use App\Models\DentalTreatmentItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Visit;
use App\Services\DentalService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The dental chart and treatment plan of one patient (roadmap 04 §3): an FDI
 * odontogram, findings per tooth and surface, the chart as it was on any date,
 * the plan from the clinic's own price list, and the next recall. On the
 * patient's record and, with `visitId`, on a visit (where findings and done
 * procedures attach to that visit).
 *
 * @property-read \App\Models\Patient $patient
 * @property-read \App\Models\Visit|null $visit
 */
#[Lazy]
class Dental extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $patientId;

    #[Locked]
    public ?int $visitId = null;

    /**
     * Which part to draw: all of it (the patient's tab), or, inside the
     * dental case sheet, the chart (Examination, hard tissues) or the plan
     * and recall (Plan and follow-up). The parts keep each other current.
     */
    #[Locked]
    public string $part = 'all';

    public bool $primary = false;

    public ?string $asOf = null;

    public ?int $tooth = null;

    /** The tapped tooth's dialog: its status as radio buttons, OK and Cancel. */
    public bool $showTooth = false;

    /** @var list<string> */
    public array $surfaces = [];

    public string $finding = 'caries';

    public ?int $mobility = null;

    public string $notes = '';

    public bool $showPlan = false;

    public ?int $serviceId = null;

    public ?int $planTooth = null;

    public string $planSurfaces = '';

    public ?string $planPrice = null;

    public string $planNotes = '';

    public ?string $recallOn = null;

    public function mount(int $patientId, ?int $visitId = null, string $part = 'all'): void
    {
        $this->patientId = $patientId;
        $this->visitId = $visitId;
        $this->part = in_array($part, ['all', 'chart', 'plan'], true) ? $part : 'all';
        $this->authorize('view', $this->patient);
        abort_unless(auth()->user()->can('treatments.view') || auth()->user()->can('visits.diagnose'), 403);
        abort_unless(auth()->user()->can('visits.clinical'), 403);
        $this->recallOn = $this->patient->next_recall_on?->toDateString();
        // A child's mouth opens on the milk teeth; the toggle still switches.
        $this->primary = $this->patient->dob !== null && $this->patient->dob->age < 12;
    }

    #[Computed]
    public function patient(): Patient
    {
        return Patient::query()->findOrFail($this->patientId);
    }

    #[Computed]
    public function visit(): ?Visit
    {
        return $this->visitId === null ? null : Visit::query()->where('patient_id', $this->patientId)->find($this->visitId);
    }

    /** @return array<int, array<string,mixed>> */
    #[Computed]
    public function chart(): array
    {
        $asOf = $this->asOf ? Carbon::parse($this->asOf)->endOfDay() : null;

        return app(DentalService::class)->chart($this->patient, $asOf);
    }

    /** @return Collection<int, \App\Models\DentalChartEntry> */
    #[Computed]
    public function history(): Collection
    {
        return app(DentalService::class)->history($this->patient, 50);
    }

    /** @return Collection<int, DentalTreatmentItem> */
    #[Computed]
    public function plan(): Collection
    {
        return app(DentalService::class)->plan($this->patient);
    }

    /** The clinic's price list, dental procedures first. @return Collection<int, Service> */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()->where('is_active', true)
            ->orderByRaw("CASE WHEN category LIKE '%dent%' THEN 0 ELSE 1 END")->orderBy('name')
            ->get(['id', 'name', 'price', 'max_price', 'category']);
    }

    /** @return list<array{0:list<int>,1:list<int>}> the rows of the odontogram: upper then lower, each patient's right to left */
    public function rows(): array
    {
        return DentalService::rows($this->primary);
    }

    /**
     * Tap a tooth: its dialog opens with the status it has now ticked. OK
     * records the change; Cancel leaves the chart as it was.
     */
    public function pick(int $tooth): void
    {
        $this->tooth = DentalService::validTooth($tooth) ? $tooth : null;
        $this->surfaces = [];
        $now = $this->tooth !== null ? ($this->chart[$this->tooth] ?? null) : null;
        $this->finding = $now['finding'] ?? DentalFinding::Sound->value;
        $this->mobility = $now['mobility'] ?? null;
        $this->notes = '';
        $this->planTooth = $this->tooth;
        $this->resetErrorBag();
        $this->showTooth = $this->tooth !== null;
        // The case sheet's diagnosis picker takes the tooth chosen here.
        if ($this->tooth !== null) {
            $this->dispatch('dental-tooth-picked', tooth: $this->tooth);
        }
    }

    /** Another part of the case sheet charted, planned or billed: read again. */
    #[On('dental-changed')]
    public function refreshDental(): void
    {
        unset($this->chart, $this->history, $this->plan, $this->patient);
        $this->recallOn = $this->patient->next_recall_on?->toDateString();
    }

    public function record(DentalService $dental): void
    {
        abort_unless(auth()->user()->can('visits.diagnose'), 403);
        if ($this->tooth === null) {
            $this->addError('tooth', 'Choose a tooth first.');

            return;
        }

        // OK on the status the tooth already has, with nothing added, is no
        // change: the chart is not written twice for nothing.
        $now = $this->chart[$this->tooth] ?? null;
        $unchanged = $this->surfaces === [] && trim($this->notes) === ''
            && $this->finding === ($now['finding'] ?? DentalFinding::Sound->value)
            && ($this->finding !== DentalFinding::Mobility->value || (int) $this->mobility === (int) ($now['mobility'] ?? -1));
        if ($unchanged) {
            $this->showTooth = false;

            return;
        }

        try {
            $dental->record($this->patient, [[
                'tooth' => $this->tooth, 'finding' => $this->finding, 'surfaces' => implode('', $this->surfaces),
                'mobility' => $this->mobility, 'notes' => $this->notes,
            ]], $this->visit, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(str_contains($key, 'notes') ? 'notes' : (str_contains($key, 'mobility') ? 'mobility' : 'finding'), $messages[0]);
            }

            return;
        }

        $this->surfaces = [];
        $this->notes = '';
        $this->asOf = null;
        $this->showTooth = false;
        unset($this->chart, $this->history);
        $this->dispatch('toast', message: "Tooth {$this->tooth}: ".(DentalFinding::from($this->finding)->label()).' recorded.', type: 'success');
        $this->dispatch('dental-changed');
    }

    public function openPlan(?int $tooth = null): void
    {
        abort_unless(auth()->user()->can('visits.diagnose'), 403);
        $this->showTooth = false;
        $this->reset(['serviceId', 'planSurfaces', 'planPrice', 'planNotes']);
        $this->planTooth = $tooth ?? $this->tooth;
        $this->planSurfaces = implode('', $this->surfaces);
        $this->resetErrorBag();
        $this->showPlan = true;
    }

    public function addToPlan(DentalService $dental): void
    {
        abort_unless(auth()->user()->can('visits.diagnose'), 403);
        $this->validate(['serviceId' => ['required', 'integer']], ['serviceId.required' => 'Choose the procedure.']);

        try {
            $dental->planItem($this->patient, Service::query()->findOrFail($this->serviceId), $this->planTooth, $this->planSurfaces, $this->planPrice, $this->planNotes, $this->visit, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key === 'price' ? 'planPrice' : ($key === 'tooth' ? 'planTooth' : 'serviceId'), $messages[0]);
            }

            return;
        }

        $this->showPlan = false;
        unset($this->plan);
        $this->dispatch('toast', message: 'Added to the treatment plan.', type: 'success');
        $this->dispatch('dental-changed');
    }

    public function move(string $uuid, string $status, DentalService $dental): void
    {
        abort_unless(auth()->user()->can('treatments.manage') || auth()->user()->can('visits.diagnose'), 403);
        $item = DentalTreatmentItem::query()->where('patient_id', $this->patientId)->where('uuid', $uuid)->firstOrFail();
        $to = DentalTreatmentStatus::from($status);

        try {
            $dental->transition($item, $to, auth()->user(), $this->visit);
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: collect($e->errors())->flatten()->first(), type: 'error');

            return;
        }

        unset($this->plan);
        $this->dispatch('toast', message: $to === DentalTreatmentStatus::Done ? 'Done, and billed on the visit.' : 'Marked '.strtolower($to->label()).'.', type: 'success');
        $this->dispatch('dental-changed');
        if ($to === DentalTreatmentStatus::Done) {
            $this->dispatch('visit-updated');
        }
    }

    public function saveRecall(DentalService $dental, ?string $preset = null): void
    {
        abort_unless(auth()->user()->can('patients.update') || auth()->user()->can('visits.diagnose'), 403);
        $on = $preset === 'default' ? DentalService::defaultRecall() : ($this->recallOn ? Carbon::parse($this->recallOn) : null);

        try {
            $dental->setRecall($this->patient, $on);
        } catch (ValidationException $e) {
            $this->addError('recallOn', collect($e->errors())->flatten()->first());

            return;
        }
        $this->recallOn = $on?->toDateString();
        unset($this->patient);
        $this->dispatch('toast', message: $on ? 'Next check-up on '.$on->format('d M Y').'.' : 'Recall cleared.', type: 'success');
    }

    public function placeholder()
    {
        return view('livewire.partials.skeleton');
    }

    public function render()
    {
        $this->authorize('view', $this->patient);

        return view('livewire.patients.tabs.dental', [
            'findings' => DentalFinding::cases(),
            'canChart' => auth()->user()->can('visits.diagnose'),
            'canMove' => auth()->user()->can('treatments.manage') || auth()->user()->can('visits.diagnose'),
            'hospitalId' => app(CurrentHospital::class)->id(),
        ]);
    }
}
