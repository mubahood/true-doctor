<?php

namespace App\Livewire\Settings;

use App\Services\PracticeService;
use App\Support\HospitalSettings;
use App\Support\Practice\Practice;
use App\Support\Practice\VisitForm;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Settings, Your practice: how a visit is written down. Which vital signs the
 * new visit form and the visit page ask for (a dental clinic: blood pressure
 * only), and, where the hospital does dentistry, whether a dental visit takes
 * simple notes or the detailed case sheet. The same PracticeService the app's
 * settings call. Changing it changes what is asked, never what was recorded.
 */
class VisitFormSettings extends Component
{
    /** @var list<string> */
    public array $vitals = [];

    public string $dentalNotes = VisitForm::DETAILED;

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
        $this->readSaved();
    }

    private function readSaved(): void
    {
        $form = VisitForm::of(app(HospitalSettings::class)->hospital());
        $this->vitals = $form->vitals;
        $this->dentalNotes = $form->dentalNotes;
    }

    /** Back to what the kind of practice suggests. */
    public function useDefaults(PracticeService $service): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
        $hospital = app(HospitalSettings::class)->hospital();
        abort_if($hospital === null, 404);

        $service->chooseVisitForm($hospital, null, null);
        $this->readSaved();
        $this->dispatch('toast', message: 'The visit form follows your kind of practice again.', type: 'success');
    }

    public function save(PracticeService $service): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
        $hospital = app(HospitalSettings::class)->hospital();
        abort_if($hospital === null, 404);

        $this->validate([
            'vitals' => ['array'],
            'vitals.*' => ['string', 'in:'.implode(',', array_keys(VisitForm::VITALS))],
            'dentalNotes' => ['required', 'in:'.implode(',', array_keys(VisitForm::DENTAL_NOTES))],
        ]);

        $practice = Practice::of($hospital);
        $vitals = VisitForm::validVitals($this->vitals);
        $form = VisitForm::of($hospital);

        // A choice that matches the practice's own default is stored as
        // "follow the practice", so a later change of practice carries it along.
        $service->chooseVisitForm(
            $hospital,
            $vitals === VisitForm::defaultVitals($practice) ? null : $vitals,
            ! $form->dentistry || $this->dentalNotes === VisitForm::defaultDentalNotes($practice) ? null : $this->dentalNotes,
        );
        $this->readSaved();

        $this->dispatch('toast', message: 'Visit form saved.', type: 'success');
    }

    public function render()
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);

        return view('livewire.settings.visit-form-settings', [
            'form' => VisitForm::of(app(HospitalSettings::class)->hospital()),
        ]);
    }
}
