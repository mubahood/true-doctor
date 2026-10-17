<?php

namespace App\Livewire\Diagnoses;

use App\Models\Visit;
use App\Services\DiagnosisService;
use App\Support\CurrentHospital;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Visits with a written diagnosis and no code yet (roadmap 04 §1): each with
 * the codes that may fit its words. A person chooses; nothing is coded on its
 * own. For the records officer, and for doctors catching up on their own.
 */
#[Layout('layouts.admin')]
class Worklist extends Component
{
    use WithPagination;

    public static function allowed(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('diagnoses.code');
    }

    public function mount(): void
    {
        abort_unless(self::allowed(), 403);
    }

    public function code(int $visitId, string $code): void
    {
        abort_unless(self::allowed(), 403);
        $visit = Visit::query()->findOrFail($visitId);
        try {
            app(DiagnosisService::class)->add($visit, $code, true, 'confirmed', null, Auth::user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: (string) collect($e->errors())->flatten()->first());

            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Coded.');
    }

    public function render(DiagnosisService $diagnoses): View
    {
        abort_unless(self::allowed(), 403);
        $visits = $diagnoses->uncoded()->with('patient:id,first_name,last_name,patient_no,uuid')->paginate(20);
        $hospitalId = app(CurrentHospital::class)->id();

        return view('livewire.diagnoses.worklist', [
            'visits' => $visits,
            'suggestions' => $visits->getCollection()->mapWithKeys(fn (Visit $v) => [$v->id => $diagnoses->suggest((string) $v->diagnosis, $hospitalId)]),
            'total' => $visits->total(),
        ])->title('Diagnoses to code');
    }
}
