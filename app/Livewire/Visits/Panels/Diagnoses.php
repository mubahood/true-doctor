<?php

namespace App\Livewire\Visits\Panels;

use App\Models\DiagnosisCode;
use App\Models\VisitDiagnosis;
use App\Services\DiagnosisService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Coded diagnoses on a visit (roadmap 04 §1): type plain words ("omusujja",
 * "high blood pressure", "B50"), see the five best matches with this
 * practice's own most-used first, one tap to add. The written diagnosis in
 * the clinical notes stays as it is; this is what the Ministry reports count.
 *
 * @property-read Collection<int, VisitDiagnosis> $current
 * @property-read Collection<int, DiagnosisCode> $matches
 * @property-read Collection<int, DiagnosisCode> $suggested
 */
class Diagnoses extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    public string $q = '';

    public bool $suspected = false;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;
        $this->authorize('view', $this->visit);
    }

    /** @return Collection<int, VisitDiagnosis> */
    #[Computed]
    public function current(): Collection
    {
        return app(DiagnosisService::class)->current($this->visit);
    }

    /** @return Collection<int, DiagnosisCode> */
    #[Computed]
    public function matches(): Collection
    {
        return app(DiagnosisService::class)->search($this->q, app(CurrentHospital::class)->id());
    }

    /** From the written diagnosis, while nothing is coded and nothing is typed. @return Collection<int, DiagnosisCode> */
    #[Computed]
    public function suggested(): Collection
    {
        $written = (string) $this->visit->diagnosis;

        return $written === '' || $this->current->isNotEmpty() ? new Collection : app(DiagnosisService::class)->suggest($written, app(CurrentHospital::class)->id());
    }

    public function add(string $code): void
    {
        $this->authorize('diagnose', $this->visit);
        try {
            app(DiagnosisService::class)->add($this->visit, $code, false, $this->suspected ? 'suspected' : 'confirmed', null, auth()->user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: (string) collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('q', 'suspected');
        unset($this->current, $this->matches, $this->suggested);
    }

    public function makePrimary(int $id): void
    {
        $this->authorize('diagnose', $this->visit);
        app(DiagnosisService::class)->change($this->find($id), primary: true, by: auth()->user());
        unset($this->current);
    }

    public function toggleCertainty(int $id): void
    {
        $this->authorize('diagnose', $this->visit);
        $d = $this->find($id);
        app(DiagnosisService::class)->change($d, certainty: $d->certainty === 'confirmed' ? 'suspected' : 'confirmed', by: auth()->user());
        unset($this->current);
    }

    public function remove(int $id): void
    {
        $this->authorize('diagnose', $this->visit);
        app(DiagnosisService::class)->remove($this->find($id), auth()->user());
        unset($this->current, $this->suggested);
    }

    private function find(int $id): VisitDiagnosis
    {
        return $this->current->firstWhere('id', $id) ?? abort(404);
    }

    public function render()
    {
        return view('livewire.visits.panels.diagnoses');
    }
}
