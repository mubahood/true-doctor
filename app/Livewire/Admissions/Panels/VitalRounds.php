<?php

namespace App\Livewire\Admissions\Panels;

use App\Http\Requests\VitalRoundRequest;
use App\Models\VitalRound;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Observation chart for one admission — an append-only log with an inline add
 * form. Replaces NursingController@storeVitals and the classic POST form on
 * admin/admissions/show.blade.php; validation still comes from
 * VitalRoundRequest::rulesFor() so the rules live in exactly one place.
 *
 * @property-read Collection<int,VitalRound> $rounds
 */
#[Lazy]
class VitalRounds extends Component
{
    use AuthorizesRequests, InteractsWithAdmission;

    public ?string $temperature = null;

    public ?string $pulse = null;

    public ?string $blood_pressure = null;

    public ?string $respiratory_rate = null;

    public ?string $spo2 = null;

    public ?string $note = null;

    /** @var list<string> */
    private const FIELDS = ['temperature', 'pulse', 'blood_pressure', 'respiratory_rate', 'spo2', 'note'];

    public function mount(int $admissionId): void
    {
        $this->admissionId = $admissionId;

        $this->authorize('view', $this->admission);
    }

    /** @return Collection<int,VitalRound> */
    #[Computed]
    public function rounds(): Collection
    {
        return $this->admission->vitalRounds()->with('recordedBy')->get();
    }

    public function add(): void
    {
        $admission = $this->guardActive();

        $data = $this->validate(VitalRoundRequest::rulesFor());
        // An untouched box is "not recorded", not an empty reading.
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $admission->vitalRounds()->create($data + [
            'recorded_by' => Auth::id(), 'created_at' => Carbon::now(),
        ]);

        $this->reset(self::FIELDS);
        unset($this->rounds);
        $this->dispatch('toast', message: 'Vitals round recorded.', type: 'success');
    }

    public function render()
    {
        $this->authorize('view', $this->admission);

        return view('livewire.admissions.panels.vital-rounds');
    }
}
