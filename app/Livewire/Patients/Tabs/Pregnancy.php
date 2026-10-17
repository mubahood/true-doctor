<?php

namespace App\Livewire\Patients\Tabs;

use App\Enums\PregnancyStatus;
use App\Models\Patient;
use App\Models\Pregnancy as PregnancyModel;
use App\Services\PregnancyService;
use App\Support\MotherChild\MotherChild;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The pregnancy record of one woman (roadmap 04 §4): dating, gravida and
 * para, risks and the birth plan, the antenatal contacts with what is due at
 * this contact and what was missed, and earlier pregnancies. Thin: every rule
 * is in PregnancyService.
 *
 * @property-read Patient $patient
 * @property-read PregnancyModel|null $current
 * @property-read Collection<int, PregnancyModel> $pregnancies
 * @property-read array<string,mixed>|null $schedule
 */
#[Lazy]
class Pregnancy extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $patientId;

    public bool $showForm = false;

    public bool $editing = false;

    public ?string $lmp = null;

    public ?string $edd = null;

    public ?int $gravida = null;

    public ?int $para = 0;

    /** @var list<string> */
    public array $riskFactors = [];

    public string $riskNotes = '';

    public string $birthPlace = '';

    public string $birthCompanion = '';

    public string $transport = '';

    public string $bloodDonor = '';

    public string $birthPlanNotes = '';

    public bool $showContact = false;

    public ?string $seenOn = null;

    public string $bloodPressure = '';

    public ?string $weight = null;

    public ?string $fundalHeight = null;

    public ?string $fetalHeart = null;

    /** @var list<string> */
    public array $checks = [];

    public string $contactNotes = '';

    public bool $showClose = false;

    public string $closeStatus = 'delivered';

    public ?string $endedOn = null;

    public string $outcomeNotes = '';

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

    #[Computed]
    public function pregnancies(): Collection
    {
        return app(PregnancyService::class)->all($this->patient)->each(fn (PregnancyModel $p) => $p->setRelation('patient', $this->patient));
    }

    #[Computed]
    public function current(): ?PregnancyModel
    {
        return $this->pregnancies->first(fn (PregnancyModel $p) => $p->status === PregnancyStatus::Active);
    }

    /** @return array<string,mixed>|null */
    #[Computed]
    public function schedule(): ?array
    {
        return $this->current === null ? null : app(PregnancyService::class)->schedule($this->current);
    }

    public function openForm(bool $edit = false): void
    {
        $this->guardRecord();
        $this->resetErrorBag();
        $this->editing = $edit && $this->current !== null;
        $p = $this->editing ? $this->current : null;
        $this->lmp = $p?->lmp?->toDateString();
        $this->edd = $p?->edd->toDateString();
        $this->gravida = $p?->gravida;
        $this->para = $p->para ?? 0;
        $this->riskFactors = (array) ($p->risk_factors ?? []);
        $this->riskNotes = (string) $p?->risk_notes;
        $this->birthPlace = (string) $p?->birth_place;
        $this->birthCompanion = (string) $p?->birth_companion;
        $this->transport = (string) $p?->transport;
        $this->bloodDonor = (string) $p?->blood_donor;
        $this->birthPlanNotes = (string) $p?->birth_plan_notes;
        $this->showForm = true;
    }

    /** The EDD the LMP gives, filled in as the LMP is typed (the clinician may still change it). */
    public function updatedLmp(): void
    {
        if ($this->lmp) {
            try {
                $this->edd = PregnancyService::eddFromLmp(Carbon::parse($this->lmp))->toDateString();
            } catch (\Throwable) {
                // A half-typed date: leave the EDD alone.
            }
        }
    }

    public function save(PregnancyService $service): void
    {
        $this->guardRecord();
        $data = [
            'lmp' => $this->lmp ?: null, 'edd' => $this->edd ?: null, 'gravida' => $this->gravida, 'para' => $this->para ?? 0,
            'risk_factors' => $this->riskFactors, 'risk_notes' => $this->riskNotes,
            'birth_place' => $this->birthPlace, 'birth_companion' => $this->birthCompanion, 'transport' => $this->transport,
            'blood_donor' => $this->bloodDonor, 'birth_plan_notes' => $this->birthPlanNotes,
        ];

        try {
            $this->editing && $this->current !== null
                ? $service->update($this->current, $data, auth()->user())
                : $service->open($this->patient, $data, auth()->user());
        } catch (ValidationException $e) {
            $this->errorsFrom($e, ['lmp' => 'lmp', 'edd' => 'edd', 'gravida' => 'gravida', 'para' => 'para', 'patient' => 'gravida']);

            return;
        }

        $this->showForm = false;
        $this->forget();
        $this->dispatch('toast', message: $this->editing ? 'Pregnancy record updated.' : 'Pregnancy recorded.', type: 'success');
    }

    public function openContact(): void
    {
        $this->guardRecord();
        $this->resetErrorBag();
        $this->reset(['bloodPressure', 'weight', 'fundalHeight', 'fetalHeart', 'checks', 'contactNotes']);
        $this->seenOn = Carbon::today()->toDateString();
        $this->showContact = true;
    }

    public function saveContact(PregnancyService $service): void
    {
        $this->guardRecord();
        if ($this->current === null) {
            return;
        }

        try {
            $service->recordContact($this->current, [
                'seen_on' => $this->seenOn, 'blood_pressure' => $this->bloodPressure, 'weight' => $this->weight,
                'fundal_height' => $this->fundalHeight, 'fetal_heart_rate' => $this->fetalHeart,
                'checks' => $this->checks, 'notes' => $this->contactNotes,
            ], null, auth()->user());
        } catch (ValidationException $e) {
            $this->errorsFrom($e, ['seen_on' => 'seenOn', 'blood_pressure' => 'bloodPressure', 'weight' => 'weight', 'fundal_height' => 'fundalHeight', 'fetal_heart_rate' => 'fetalHeart', 'checks' => 'checks', 'pregnancy' => 'seenOn']);

            return;
        }

        $this->showContact = false;
        $this->forget();
        $this->dispatch('toast', message: 'Antenatal contact recorded.', type: 'success');
    }

    public function openClose(): void
    {
        $this->guardRecord();
        $this->resetErrorBag();
        $this->closeStatus = PregnancyStatus::Delivered->value;
        $this->endedOn = Carbon::today()->toDateString();
        $this->outcomeNotes = '';
        $this->showClose = true;
    }

    public function close(PregnancyService $service): void
    {
        $this->guardRecord();
        if ($this->current === null) {
            return;
        }
        $status = PregnancyStatus::tryFrom($this->closeStatus);
        if ($status === null) {
            $this->addError('closeStatus', 'Choose how the pregnancy ended.');

            return;
        }

        try {
            $service->close($this->current, $status, $this->endedOn ? Carbon::parse($this->endedOn) : null, $this->outcomeNotes, auth()->user());
        } catch (ValidationException $e) {
            $this->errorsFrom($e, ['status' => 'closeStatus', 'ended_on' => 'endedOn', 'pregnancy' => 'endedOn']);

            return;
        }

        $this->showClose = false;
        $this->forget();
        $this->dispatch('toast', message: 'Pregnancy closed.', type: 'success');
    }

    private function guardRecord(): void
    {
        abort_unless(MotherChild::canRecord(auth()->user()), 403);
    }

    private function forget(): void
    {
        unset($this->pregnancies, $this->current, $this->schedule);
    }

    /** @param array<string,string> $map service key => property */
    private function errorsFrom(ValidationException $e, array $map): void
    {
        foreach ($e->errors() as $key => $messages) {
            $this->addError($map[$key] ?? $key, $messages[0]);
        }
    }

    public function placeholder()
    {
        return view('livewire.partials.skeleton');
    }

    public function render()
    {
        $this->authorize('view', $this->patient);

        return view('livewire.patients.tabs.pregnancy', [
            'canRecord' => MotherChild::canRecord(auth()->user()),
            'riskLabels' => PregnancyService::riskFactors(),
            'checkLabels' => array_map(fn ($c) => $c['label'], PregnancyService::checks()),
        ]);
    }
}
