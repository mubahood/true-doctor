<?php

namespace App\Livewire\Visits;

use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PatientSex;
use App\Enums\VisitStage;
use App\Exceptions\PlanLimitExceededException;
use App\Http\Requests\VisitIntakeRequest;
use App\Http\Requests\VisitRequest;
use App\Livewire\Concerns\ChoosesPhrases;
use App\Livewire\Concerns\WithTable;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\VisitService;
use App\Support\HospitalSettings;
use App\Support\PatientBrief;
use App\Support\VisitPhrases;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Visit pipeline — search, stage filter, and the dialog that starts a visit.
 *
 * The dialog asks three things in order — who, where it is going, why — and
 * each answer narrows the next (docs/visits.md):
 *
 *   patient    → what we already know about them: an open visit they should be
 *                sent back to instead, an appointment today this visit can
 *                fulfil, an unpaid balance the desk should see now
 *   department → the doctor box stops offering the whole hospital
 *   doctor     → their department fills itself in, if it was left blank
 *
 * Validation stays shared with VisitRequest / VisitIntakeRequest (§4.5, C10),
 * and every picker is <livewire:ui.select-search>, so no whole table is ever
 * queried to render a dropdown (C4/D3/L1).
 *
 * @property-read array<string,mixed> $patientBrief
 * @property-read list<string> $reasonSuggestions
 * @property-read array<string,string> $sexes
 * @property-read array<string,string> $statuses
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, ChoosesPhrases, WithTable;

    /** Modal fields a <livewire:ui.select-search> child may set. */
    /**
     * A visit is not attached to a department or a doctor — its ORDERS are.
     * Reception opens a visit before anybody knows who will see the patient,
     * and the doctor is recorded where the clinical work is (the notes panel,
     * which has its own doctor picker). The columns stay because check-in fills
     * them from the appointment; this dialog simply stops asking.
     */
    private const PICKERS = ['patient_id'];

    private const TABS = ['existing', 'intake'];

    /**
     * The two filters answer the two questions the record now answers.
     *
     * `status` is flattened to what a reader means by it — Pending, Ongoing,
     * and the two ways a visit ends — because "completed" and "cancelled" are
     * the words people search by, not "completed with outcome cancelled".
     */
    #[Url(history: true)]
    public string $status = '';

    #[Url(history: true)]
    public string $stage = '';

    // ── Open-visit modal state ─────────────────────────────
    public bool $showForm = false;

    /** 'existing' = pick a registered patient · 'intake' = register + open. */
    public string $tab = 'existing';

    /**
     * Bumped every time the dialog opens and every time a pick changes what
     * another picker may offer. It is part of each picker's key, so the child
     * remounts instead of keeping a selection the parent has already dropped.
     */
    public int $formNonce = 0;

    public ?int $patient_id = null;

    /** Optional link to the appointment this visit fulfils. */
    public ?int $appointment_id = null;

    public ?string $reason = null;

    public ?string $complaints = null;

    /** What was found, where the person opening the visit already knows it. */
    public ?string $diagnosis = null;

    /**
     * Is anybody seeing them yet?
     *
     * False opens it Pending — registered at the desk, waiting. True sends it
     * through VisitService::start(), which is the ONLY thing that moves a visit
     * to Ongoing, so the trail records the move rather than the visit simply
     * appearing mid-flight.
     */
    public bool $start_now = false;

    // ── Vitals, taken at the desk ──────────────────────────────
    public ?string $temperature = null;

    public ?string $blood_pressure = null;

    public ?string $pulse = null;

    public ?string $respiratory_rate = null;

    public ?string $spo2 = null;

    public ?string $weight = null;

    public ?string $height = null;

    // ── Intake tab (new patient) ───────────────────────────────
    public ?string $first_name = null;

    public ?string $last_name = null;

    public ?string $sex = null;

    public ?string $dob = null;

    public ?string $phone_1 = null;

    public bool $consent_given = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Visit::class);
    }

    protected function resetsPage(): array
    {
        return ['status', 'stage'];
    }

    /** Only columns that are columns — a doctor's name lives on another table. */
    protected function sortableFields(): array
    {
        return ['visit_no', 'status', 'stage', 'created_at'];
    }

    public function create(): void
    {
        $this->authorize('create', Visit::class);
        $this->resetForm();
        $this->tab = 'existing';
        $this->showForm = true;
    }

    /**
     * Press the gate open, from the row menu.
     *
     * No target: there is only ever one next stage, and whether it can be
     * reached is the visit's own business, not the caller's (docs/visits.md).
     */
    public function advanceVisit(int $visitId, VisitService $service): void
    {
        /** @var Visit $visit */
        $visit = Visit::findOrFail($visitId);
        $this->authorize('manage', $visit);

        try {
            $service->advance($visit, auth()->id());
        } catch (\App\Exceptions\InvalidVisitTransitionException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('toast', message: 'Now at '.$visit->fresh()?->stage->label().'.', type: 'success');
        $this->dispatch('visit-updated');
    }

    /** Send the reader to the visit this patient already has open. */
    public function openExisting(): void
    {
        $open = $this->patientBrief['open_visit'] ?? null;
        if ($open === null) {
            return;
        }

        $this->showForm = false;
        $this->redirect(route('admin.visits.show', $open['uuid']), navigate: true);
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true)) {
            return;
        }
        $this->tab = $tab;
        $this->formNonce++;
        $this->resetErrorBag();
        unset($this->patientBrief, $this->reasonSuggestions);
    }

    /**
     * A <livewire:ui.select-search> child reports a pick — and each pick makes
     * the next question easier to answer.
     */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if (! in_array($name, self::PICKERS, true)) {
            return;
        }

        $this->{$name} = $id;

        $this->patientChanged();
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if (! in_array($name, self::PICKERS, true)) {
            return;
        }

        $this->{$name} = null;
        $this->patientChanged();
    }

    // ── What each answer changes ─────────────────────────────────────────

    /** A new patient means a new set of facts, and no stale appointment link. */
    private function patientChanged(): void
    {
        $this->appointment_id = null;
        unset($this->patientBrief, $this->reasonSuggestions);
    }

    // ── What we already know ─────────────────────────────────────────────

    /**
     * Everything the desk should see the moment a patient is chosen — an open
     * visit, today's booking, money owed (App\Support\PatientBrief, which the
     * app's dialog shows too).
     *
     * @return array{}|array<string,mixed>
     */
    #[Computed]
    public function patientBrief(): array
    {
        if ($this->patient_id === null) {
            return [];
        }

        /** @var Patient|null $patient */
        $patient = Patient::whereKey($this->patient_id)->first();

        return $patient === null ? [] : PatientBrief::for($patient);
    }

    /**
     * Fulfil the booking: the visit carries the appointment, and inherits what
     * was already agreed rather than making the desk retype it.
     */
    public function linkAppointment(): void
    {
        $booking = $this->patientBrief['appointment'] ?? null;
        if ($booking === null) {
            return;
        }

        /** @var Appointment|null $appointment */
        $appointment = Appointment::whereKey($booking['id'])->with('doctor')->first();
        if ($appointment === null) {
            return;
        }

        $this->appointment_id = $appointment->id;

        // The booking's doctor and department are carried by the SERVICE when
        // the visit is opened against it, not by this form — the form no longer
        // has anywhere to put them.
        if (($this->reason ?? '') === '') {
            $this->reason = $appointment->reason;
        }

        $this->formNonce++;
        unset($this->reasonSuggestions);
    }

    public function unlinkAppointment(): void
    {
        $this->appointment_id = null;
    }

    // ── Why they came ────────────────────────────────────────────────────

    /**
     * How this hospital writes down why someone came.
     *
     * What the desk actually types comes first, because a hospital's own words
     * beat a curated list; the curated set only tops it up to a usable number.
     *
     * @return list<string>
     */
    #[Computed]
    public function reasonSuggestions(): array
    {
        return VisitPhrases::reasons();
    }

    /** Only ever a phrase that is actually on offer. */
    /**
     * The three fields a suggestion may be written into.
     *
     * Each of them holds a LIST written as prose — a patient comes in with
     * more than one complaint, and often for more than one reason.
     *
     * @return list<string>
     */
    protected function phraseFields(): array
    {
        return ['reason', 'complaints', 'diagnosis'];
    }

    /**
     * Only a phrase this form actually offered.
     *
     * `togglePhrase` guards the FIELD; this guards the VALUE, so the pills
     * cannot be used to write arbitrary text into the record.
     */
    public function usePhrase(string $field, string $phrase): void
    {
        $offered = match ($field) {
            'reason' => $this->reasonSuggestions,
            'complaints' => $this->clinicalPhrases['complaints'] ?? [],
            'diagnosis' => $this->clinicalPhrases['diagnosis'] ?? [],
            default => [],
        };

        if (in_array($phrase, $offered, true)) {
            $this->togglePhrase($field, $phrase);
        }
    }

    /**
     * Fields this dialog does not ask for.
     *
     * The FormRequests stay the one source of truth for what a visit accepts —
     * an HTTP path may still post a doctor, and check-in does — but Livewire
     * validates only properties that exist, so what the dialog does not own is
     * filtered out rather than deleted from the shared rules.
     */
    private const NOT_ASKED_HERE = ['doctor_user_id', 'department_id'];

    /** Shared with the FormRequests — one source of truth per tab (§4.5, C10). */
    protected function rules(): array
    {
        if ($this->tab === 'intake') {
            return array_diff_key(VisitIntakeRequest::rulesFor(), array_flip(self::NOT_ASKED_HERE));
        }

        $rules = array_diff_key(VisitRequest::rulesFor(), array_flip(self::NOT_ASKED_HERE));

        // The dialog only ever offers this patient's own appointments, but the
        // property is public and the browser is not trusted.
        $rules['appointment_id'][] = VisitRequest::appointmentIsTheirs($this->patient_id);

        return $rules;
    }

    public function save(VisitService $service): void
    {
        $this->authorize('create', Visit::class);

        if ($this->tab === 'intake') {
            $this->saveIntake($service);

            return;
        }

        $data = $this->validate();
        foreach (['reason', 'complaints', 'diagnosis'] as $k) {
            if (($data[$k] ?? '') === '') {
                $data[$k] = null;
            }
        }

        try {
            $visit = $service->open(
                array_intersect_key($data, array_flip(['patient_id', 'appointment_id', 'reason', 'complaints', 'diagnosis'])),
                auth()->id(),
            );
            $visit = $this->recordWhatWasTaken($visit, $service);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'The visit could not be opened. Please try again.', type: 'error');

            return;
        }

        $this->finish($visit->visit_no, $visit, 'Visit opened');
    }

    /** Register a brand-new patient and open the visit in one transaction. */
    private function saveIntake(VisitService $service): void
    {
        $this->authorize('create', Patient::class);

        // Mirrors VisitIntakeRequest::prepareForValidation(); empty
        // optionals must be null *before* validation (no ConvertEmptyStringsToNull
        // on the Livewire wire) or `date`/`Enum` would reject ''.
        $this->consent_given = (bool) $this->consent_given;
        foreach (['sex', 'dob', 'phone_1', 'reason', 'complaints'] as $k) {
            if (is_string($this->{$k}) && trim($this->{$k}) === '') {
                $this->{$k} = null;
            }
        }

        $data = $this->validate();

        $patientData = array_intersect_key($data, array_flip(['first_name', 'last_name', 'sex', 'dob', 'phone_1', 'consent_given']));
        $visitData = array_intersect_key($data, array_flip(['reason', 'complaints', 'diagnosis']));

        try {
            $visit = $service->intake($patientData, $visitData, auth()->id());
            $visit = $this->recordWhatWasTaken($visit, $service);
        } catch (PlanLimitExceededException|\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'The patient could not be registered. Please try again.', type: 'error');

            return;
        }

        $this->finish($visit->visit_no, $visit, 'New patient registered and visit opened');
    }

    /** The vitals taken at the desk, and whether anybody is seeing them yet — VisitService decides both. */
    private function recordWhatWasTaken(Visit $visit, VisitService $service): Visit
    {
        return $service->recordWhatWasTaken($visit, [
            'temperature' => $this->temperature,
            'blood_pressure' => $this->blood_pressure,
            'pulse' => $this->pulse,
            'respiratory_rate' => $this->respiratory_rate,
            'spo2' => $this->spo2,
            'weight' => $this->weight,
            'height' => $this->height,
            'start_now' => $this->start_now,
        ], auth()->id());
    }

    /**
     * The dialog closes and the list is where it was.
     *
     * It used to redirect to the new visit's own page, which threw whoever
     * opened it out of the list they were working down — and the commonest
     * thing after registering somebody is registering the next person, not
     * reading the record you have just created. The visit is in the table
     * behind the dialog, and the toast names it.
     */
    private function finish(string $number, Visit $visit, string $verb): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetPage();

        $this->dispatch('toast', message: "{$verb} — {$number}.", type: 'success');
    }

    private function resetForm(): void
    {
        $this->reset([
            'patient_id', 'appointment_id', 'reason', 'complaints', 'diagnosis', 'start_now',
            'temperature', 'blood_pressure', 'pulse', 'respiratory_rate', 'spo2', 'weight', 'height',
            'first_name', 'last_name', 'sex', 'dob', 'phone_1', 'consent_given',
        ]);
        $this->formNonce++;
        $this->resetErrorBag();
        unset($this->patientBrief, $this->reasonSuggestions);
    }

    /**
     * The BMI, shown as it is typed.
     *
     * Worked out by VisitService — the same method the vitals panel uses — so
     * the figure previewed here is the figure stored, to the same rounding.
     */
    #[Computed]
    public function bmiPreview(): ?string
    {
        $bmi = app(VisitService::class)->computeBmi(
            is_numeric($this->weight) ? (float) $this->weight : null,
            is_numeric($this->height) ? (float) $this->height : null,
        );

        return $bmi === null ? null : number_format($bmi, 2);
    }

    /**
     * The phrases the clinical notes offer, offered here too.
     *
     * One catalogue, so what reception can click is what the consulting room
     * can click — two lists would drift and a record would read as though two
     * different hospitals wrote it.
     *
     * @return array<string,list<string>>
     */
    #[Computed]
    public function clinicalPhrases(): array
    {
        return VisitPhrases::desk();
    }

    /** @return array<string, string> */
    #[Computed]
    public function sexes(): array
    {
        return PatientSex::options();
    }

    /**
     * Is this visit alive — and, if it is over, how did it end?
     *
     * Flattened on purpose: nobody filters for "completed with outcome
     * cancelled", they filter for cancelled ones.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function statuses(): array
    {
        return Visit::stateOptions();
    }

    /** And what is being done to it right now. @return array<string, string> */
    #[Computed]
    public function stages(): array
    {
        return VisitStage::options();
    }

    /**
     * What each row owes, worked out from the sums the query already fetched.
     *
     * Two figures, and they mean different things depending on where the visit
     * has got to (docs/billing.md):
     *
     *   TOTAL    once invoiced, the invoice's own total — a frozen figure that
     *            a later price change must never move. Before that, what the
     *            work on the visit comes to right now, with its standing
     *            discount and the hospital's tax applied by the same
     *            BillingService arithmetic the bill panel uses.
     *   BALANCE  what is still to pay, which only exists once there is an
     *            invoice to pay. Before that it is not zero, it is nothing —
     *            and the column says so rather than implying a settled bill.
     *
     * @param  LengthAwarePaginator<int,Visit>  $rows
     * @return LengthAwarePaginator<int,Visit>
     */
    private function withMoney(LengthAwarePaginator $rows): LengthAwarePaginator
    {
        $billing = app(BillingService::class);

        foreach ($rows->items() as $visit) {
            $invoiced = (int) ($visit->live_invoices_count ?? 0) > 0;

            if ($invoiced) {
                $visit->bill_total = HospitalSettings::decimal((string) ($visit->invoiced_total ?? '0'), 2);
                $visit->bill_balance = HospitalSettings::decimal((string) ($visit->invoiced_balance ?? '0'), 2);

                continue;
            }

            $subtotal = HospitalSettings::decimal((string) ($visit->billed_subtotal ?? '0'), 2);

            $figures = $billing->compute(
                $subtotal,
                (string) ($visit->billed_taxable ?? '0'),
                ($visit->discount_type ?? DiscountType::Amount)
                    ->resolve((string) ($visit->discount_value ?? '0'), $subtotal),
            );

            $visit->bill_total = $figures['due'];
            $visit->bill_balance = null;
        }

        return $rows;
    }

    public function render()
    {
        $this->authorize('viewAny', Visit::class);

        // The row menu must not offer a move the click would refuse, so each
        // row carries what its gate needs: how many of its orders are still
        // open, and whether it has a live invoice. Both are subqueries on the
        // one statement — asking the service per row would be an N+1.
        $visits = Visit::with(['patient', 'doctor'])
            ->withCount('orders')
            ->withCount(['orders as open_orders_count' => fn (Builder $q) => $q->whereIn(
                'status', [OrderStatus::Pending->value, OrderStatus::InProgress->value],
            )])
            ->withCount(['invoices as live_invoices_count' => fn (Builder $q) => $q->where(
                'status', '!=', InvoiceStatus::Void->value,
            )])
            // The money, added up in the same statement. A page of twenty
            // visits would otherwise load every line of every bill to show two
            // figures per row.
            ->withSum(['orderItems as billed_subtotal' => fn (Builder $q) => $q->where(
                'order_items.status', '!=', OrderItemStatus::Cancelled->value,
            )], 'line_total')
            ->withSum(['orderItems as billed_taxable' => fn (Builder $q) => $q
                ->where('order_items.status', '!=', OrderItemStatus::Cancelled->value)
                ->where('order_items.tax_exempt', false)], 'line_total')
            // At most one live invoice per visit, so a SUM is that invoice.
            ->withSum(['invoices as invoiced_total' => fn (Builder $q) => $q->where(
                'status', '!=', InvoiceStatus::Void->value,
            )], 'total')
            ->withSum(['invoices as invoiced_balance' => fn (Builder $q) => $q->where(
                'status', '!=', InvoiceStatus::Void->value,
            )], 'balance')
            ->inState($this->status)
            ->when($this->stage !== '', fn (Builder $q) => $q->where('stage', $this->stage))
            ->matching($this->search)
            ->tap(fn (Builder $q) => $this->applySort($q, fn (Builder $qq) => $qq->latest('id')));

        /** @var LengthAwarePaginator<int,Visit> $rows */
        $rows = $visits->paginate($this->perPage);

        return view('livewire.visits.index', ['rows' => $this->withMoney($rows)])->title('Visits');
    }
}
