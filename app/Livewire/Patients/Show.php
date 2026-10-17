<?php

namespace App\Livewire\Patients;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\PatientService;
use App\Services\PatientSummaryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The patient record - everything about one person (docs/patients.md, "The
 * patient record").
 *
 * A header with the actions, an alert strip for what must not be missed, a row
 * of figures, then tabs. The header, alerts and figures come from
 * PatientSummaryService (the same service the app's summary endpoint reads);
 * each tab is its own #[Lazy] component that owns its data and its abilities,
 * mounted the first time the tab is opened and kept (hidden) afterwards.
 *
 * A tab is shown only to someone who may see what is in it and only when the
 * practice uses the module behind it (a dental clinic has no admissions); a
 * ?tab= for a tab you may not have lands on the overview.
 *
 * Thin (house rule 4): mount/render authorize; the only write here archives the
 * patient through PatientService and redirects to the index.
 *
 * @property-read list<string> $sections
 * @property-read array<string,string> $tabs
 * @property-read array<string,mixed> $figures
 * @property-read list<array<string,mixed>> $alerts
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests;

    public const TABS = [
        'overview' => ['Overview', 'fa-gauge'],
        'visits' => ['Visits', 'fa-stethoscope'],
        'billing' => ['Billing', 'fa-file-invoice-dollar'],
        'clinical' => ['Clinical', 'fa-notes-medical'],
        'appointments' => ['Appointments', 'fa-calendar-check'],
        'records' => ['Records', 'fa-folder-open'],
        'details' => ['Details', 'fa-id-badge'],
        'access' => ['Who opened it', 'fa-user-shield'],
    ];

    #[Locked]
    public Patient $patient;

    #[Url(history: true, except: 'overview')]
    public string $tab = 'overview';

    /** Tabs opened so far: each is mounted once and kept, so switching back is instant. @var list<string> */
    #[Locked]
    public array $opened = [];

    public function mount(Patient $patient): void
    {
        $this->authorize('view', $patient);

        $this->patient = $patient;
        $this->settleTab();

        // The read audit: once per person per record in a short window (docs/audit.md).
        app(\App\Services\Audit\RecordAccessLog::class)->opened($patient, auth()->user());
    }

    /** A hand-typed or stale ?tab= is settled on every request. */
    public function booted(): void
    {
        $this->settleTab();
    }

    private function settleTab(): void
    {
        if (! array_key_exists($this->tab, $this->tabs)) {
            $this->tab = 'overview';
        }

        if (! in_array($this->tab, $this->opened, true)) {
            $this->opened[] = $this->tab;
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->settleTab();
    }

    /** A tab's "view all" link, dispatched from inside the tab. */
    #[On('patient-tab')]
    public function openTab(string $tab): void
    {
        $this->setTab($tab);
    }

    /** A panel changed something the page mirrors - re-read the row and the figures. */
    #[On('patient-updated')]
    public function refreshPatient(): void
    {
        $this->patient = $this->patient->fresh() ?? $this->patient;
        unset($this->figures, $this->alerts);
    }

    /** @return list<string> */
    #[Computed]
    public function sections(): array
    {
        return PatientSummaryService::sectionsFor(auth()->user());
    }

    /** The tabs this person may open, label first and icon second. @return array<string,array{0:string,1:string}> */
    #[Computed]
    public function tabs(): array
    {
        $needs = ['visits' => 'visits', 'billing' => 'money', 'clinical' => 'clinical', 'appointments' => 'appointments'];

        $tabs = array_filter(self::TABS, fn (string $key) => ! isset($needs[$key]) || in_array($needs[$key], $this->sections, true), ARRAY_FILTER_USE_KEY);

        // The read audit is the owner's to see (docs/audit.md).
        if (! auth()->user()?->can('manage-settings')) {
            unset($tabs['access']);
        }

        // The dental chart, next to the clinical history, where there is
        // dentistry to chart (DentalService::onPatient, docs/dental.md).
        if (\App\Services\DentalService::onPatient($this->patient, app(\App\Support\HospitalSettings::class)->hospital(), auth()->user())) {
            $tabs = array_slice($tabs, 0, 4, true) + ['dental' => ['Dental', 'fa-tooth']] + array_slice($tabs, 4, null, true);
        }

        return $tabs;
    }

    /**
     * The messaging answers in force (roadmap 05 §2), newest per channel and purpose.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PatientConsent>
     */
    #[Computed]
    public function consents(): \Illuminate\Database\Eloquent\Collection
    {
        return app(\App\Services\Messaging\ConsentService::class)->latest($this->patient)->load('recordedBy:id,name');
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function figures(): array
    {
        return app(PatientSummaryService::class)->figures($this->patient, $this->sections);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function alerts(): array
    {
        return app(PatientSummaryService::class)->alerts($this->patient, $this->sections, $this->figures);
    }

    public function archive(PatientService $service)
    {
        $this->authorize('delete', $this->patient);

        $number = $this->patient->patient_no;
        $service->archive($this->patient);

        session()->flash('success', "Patient {$number} archived.");

        return $this->redirect(route('admin.patients.index'), navigate: true);
    }

    public function render()
    {
        $this->authorize('view', $this->patient);

        $this->patient->loadMissing(['district', 'registeredBy']);

        return view('livewire.patients.show', [
            'canNewVisit' => auth()->user()->can('create', Visit::class),
            'canBook' => auth()->user()->can('create', Appointment::class),
            'canStatement' => in_array('money', $this->sections, true),
        ])->title($this->patient->full_name);
    }
}
