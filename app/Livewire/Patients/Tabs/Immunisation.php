<?php

namespace App\Livewire\Patients\Tabs;

use App\Models\Immunisation as ImmunisationModel;
use App\Models\Patient;
use App\Services\ImmunisationService;
use App\Support\MotherChild\MotherChild;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A child's immunisation card (roadmap 04 §4): every dose of the national
 * schedule with its date, given, due, overdue, upcoming or missed; a dose is
 * recorded from its row, and a wrong entry is voided with a reason. Thin:
 * every rule is in ImmunisationService.
 *
 * @property-read Patient $patient
 * @property-read list<array<string,mixed>> $card
 */
#[Lazy]
class Immunisation extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $patientId;

    public bool $showGive = false;

    public ?string $doseKey = null;

    public ?string $givenOn = null;

    public bool $givenElsewhere = false;

    public string $batchNo = '';

    public bool $showVoid = false;

    public ?string $voidUuid = null;

    public string $voidReason = '';

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;
        $this->authorize('view', $this->patient);
        abort_unless(MotherChild::canView(auth()->user()), 403);
    }

    #[Computed]
    public function patient(): Patient
    {
        return Patient::query()->findOrFail($this->patientId);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function card(): array
    {
        return app(ImmunisationService::class)->card($this->patient);
    }

    public function openGive(string $key): void
    {
        abort_unless(MotherChild::canRecord(auth()->user()), 403);
        $this->resetErrorBag();
        $this->doseKey = $key;
        $this->givenOn = Carbon::today()->toDateString();
        $this->givenElsewhere = false;
        $this->batchNo = '';
        $this->showGive = true;
    }

    public function give(ImmunisationService $service): void
    {
        abort_unless(MotherChild::canRecord(auth()->user()), 403);

        try {
            $service->record($this->patient, [[
                'dose_key' => $this->doseKey, 'given_on' => $this->givenOn,
                'given_elsewhere' => $this->givenElsewhere, 'batch_no' => $this->batchNo,
            ]], null, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(str_ends_with($key, 'given_on') ? 'givenOn' : 'doseKey', $messages[0]);
            }

            return;
        }

        $this->showGive = false;
        unset($this->card);
        $this->dispatch('toast', message: 'Dose recorded.', type: 'success');
    }

    public function openVoid(string $uuid): void
    {
        abort_unless(MotherChild::canRecord(auth()->user()), 403);
        $this->resetErrorBag();
        $this->voidUuid = $uuid;
        $this->voidReason = '';
        $this->showVoid = true;
    }

    public function void(ImmunisationService $service): void
    {
        abort_unless(MotherChild::canRecord(auth()->user()), 403);
        $dose = ImmunisationModel::query()->where('patient_id', $this->patientId)->where('uuid', $this->voidUuid)->firstOrFail();

        try {
            $service->void($dose, $this->voidReason, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('voidReason', collect($e->errors())->flatten()->first());

            return;
        }

        $this->showVoid = false;
        unset($this->card);
        $this->dispatch('toast', message: 'Entry voided. The dose can be recorded again.', type: 'success');
    }

    public function placeholder()
    {
        return view('livewire.partials.skeleton');
    }

    public function render()
    {
        $this->authorize('view', $this->patient);
        $dose = $this->doseKey ? (config('mother_child.immunisation.schedule')[$this->doseKey] ?? null) : null;

        return view('livewire.patients.tabs.immunisation', [
            'canRecord' => MotherChild::canRecord(auth()->user()),
            'counts' => ImmunisationService::counts($this->card),
            'doseName' => $dose ? $dose['vaccine'].' · '.$dose['dose'] : '',
        ]);
    }
}
