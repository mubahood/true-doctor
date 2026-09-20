<?php

namespace App\Livewire\Admissions\Panels;

use App\Enums\MedicationAdminStatus;
use App\Http\Requests\MedicationAdministrationRequest;
use App\Models\MedicationAdministration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Medication administration record (MAR) for one admission — an append-only log
 * with an inline add form. Replaces NursingController@storeMedication and the
 * classic POST form on admin/admissions/show.blade.php; validation comes from
 * MedicationAdministrationRequest::rulesFor().
 *
 * @property-read Collection<int,MedicationAdministration> $entries
 */
#[Lazy]
class Medications extends Component
{
    use AuthorizesRequests, InteractsWithAdmission;

    public ?string $drug_name = null;

    public ?string $dose = null;

    public ?string $route = null;

    public string $status = 'given';

    public ?string $note = null;

    /** @var list<string> */
    private const FIELDS = ['drug_name', 'dose', 'route', 'note'];

    public function mount(int $admissionId): void
    {
        $this->admissionId = $admissionId;

        $this->authorize('view', $this->admission);
    }

    /** @return Collection<int,MedicationAdministration> */
    #[Computed]
    public function entries(): Collection
    {
        return $this->admission->medications()->with('administeredBy')->get();
    }

    /** @return array<string,string> */
    #[Computed]
    public function statuses(): array
    {
        return MedicationAdminStatus::options();
    }

    public function add(): void
    {
        $admission = $this->guardActive();

        $data = $this->validate(MedicationAdministrationRequest::rulesFor());
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $admission->medications()->create($data + [
            'administered_by' => Auth::id(), 'created_at' => Carbon::now(),
        ]);

        $this->reset(self::FIELDS);
        $this->status = MedicationAdminStatus::Given->value;
        unset($this->entries);
        $this->dispatch('toast', message: 'Medication recorded.', type: 'success');
    }

    public function render()
    {
        $this->authorize('view', $this->admission);

        return view('livewire.admissions.panels.medications');
    }
}
