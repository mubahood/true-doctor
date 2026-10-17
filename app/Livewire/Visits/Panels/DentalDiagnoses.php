<?php

namespace App\Livewire\Visits\Panels;

use App\Models\DiagnosisCode;
use App\Services\DentalCaseSheetService;
use App\Services\DiagnosisService;
use App\Support\CaseSheet\DentalCaseSheet;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The diagnosis of the dental case sheet: a tooth (taken from the chart when
 * one is chosen there) and a diagnosis from the short dental list, one tap
 * each. Each is coded for the Ministry reports and marks the tooth on the
 * chart. Anything not on the list is found by the ordinary diagnosis search.
 *
 * @property-read list<array<string,mixed>> $current
 * @property-read Collection<int, DiagnosisCode> $matches
 */
class DentalDiagnoses extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    public ?int $tooth = null;

    /** @var list<string> */
    public array $surfaces = [];

    public bool $suspected = false;

    public string $q = '';

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;
        $this->authorize('view', $this->visit);
        abort_unless(DentalCaseSheetService::canRead(auth()->user()), 403);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function current(): array
    {
        return app(DentalCaseSheetService::class)->diagnosisRows($this->visit);
    }

    /** @return Collection<int, DiagnosisCode> */
    #[Computed]
    public function matches(): Collection
    {
        return trim($this->q) === '' ? new Collection : app(DiagnosisService::class)->search($this->q, app(CurrentHospital::class)->id());
    }

    /** The chart's chosen tooth becomes the tooth here. */
    #[On('dental-tooth-picked')]
    public function toothPicked(int $tooth): void
    {
        $this->tooth = $tooth;
        $this->surfaces = [];
    }

    #[On('dental-changed')]
    public function refresh(): void
    {
        unset($this->current);
    }

    public function add(string $key, DentalCaseSheetService $sheets): void
    {
        $this->authorize('diagnose', $this->visit);
        $item = DentalCaseSheet::DIAGNOSES[$key] ?? null;
        if ($item === null) {
            return;
        }

        try {
            $sheets->addDiagnosis($this->visit, $key, $this->tooth, $this->surfaceLetters(), auth()->user(), $this->suspected);
        } catch (ValidationException $e) {
            $this->addError('tooth', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->resetErrorBag();
        $this->suspected = false;
        $this->surfaces = [];
        $this->changed($item['label'].($item['tooth'] !== false && $this->tooth !== null ? ', tooth '.$this->tooth : '').' added.');
    }

    /** A diagnosis from the full list, on the chosen tooth if there is one. */
    public function addCode(string $code, DentalCaseSheetService $sheets): void
    {
        $this->authorize('diagnose', $this->visit);
        try {
            // Only a dental code takes the chosen tooth: hypertension is not on 36.
            $dental = preg_match('/^(K0[0-8]|S02\.5)/', strtoupper($code)) === 1;
            $sheets->addCoded($this->visit, $code, $dental ? $this->tooth : null, $dental ? $this->surfaceLetters() : null, auth()->user(), $this->suspected);
        } catch (ValidationException $e) {
            $this->addError('q', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->reset('q', 'suspected');
        $this->changed('Diagnosis added.');
    }

    public function remove(int $id, DentalCaseSheetService $sheets): void
    {
        $this->authorize('diagnose', $this->visit);
        try {
            $sheets->removeDiagnosis($this->visit, $id, auth()->user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: (string) collect($e->errors())->flatten()->first(), type: 'error');

            return;
        }
        $this->changed('Diagnosis removed.');
    }

    private function surfaceLetters(): string
    {
        return implode('', array_filter($this->surfaces, 'is_string'));
    }

    private function changed(string $message): void
    {
        unset($this->current, $this->matches);
        $this->dispatch('toast', message: $message, type: 'success');
        $this->dispatch('dental-changed');
    }

    public function render()
    {
        return view('livewire.visits.panels.dental-diagnoses', [
            'list' => DentalCaseSheet::DIAGNOSES,
            'canWrite' => auth()->user()->can('diagnose', $this->visit),
        ]);
    }
}
