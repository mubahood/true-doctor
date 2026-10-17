<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ClaimStatus;
use App\Enums\PatientStatus;
use App\Enums\VisitStatus;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\DoseItem;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\Payment;
use App\Models\RadiologyOrder;
use App\Models\User;
use App\Models\Visit;
use App\Support\Practice\Practice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the system knows about ONE patient, in one place (docs/patients.md,
 * "The patient record").
 *
 * The patient page and `GET /api/v1/patients/{uuid}/summary` both read from
 * here, so the web and the app cannot disagree about what a patient owes or
 * when they were last seen.
 *
 * Rules it keeps:
 *  - AGGREGATES, NOT LOOPS. Each figure is one query; each recent list is one
 *    query plus (at most) one batched lookup for the rows' totals.
 *  - TENANCY comes from the global scope on every model - nothing here filters
 *    a hospital by hand.
 *  - MONEY is a decimal string at scale 2 and never a float. It is formatted
 *    for display by HospitalSettings::format() in the views, not here.
 *  - SECTIONS. What a person may see depends on their permissions and on the
 *    practice's modules ({@see self::sectionsFor()}). A section a caller was
 *    not given is OMITTED from every result, not returned empty: an empty money
 *    block would read as "owes nothing".
 *  - "OUTSTANDING" is Invoice::outstanding(), the same scope the reports use.
 *    Only the age bands differ (30-day bands suit one account; the report's
 *    7-day first band suits a hospital).
 *
 * Dates are ISO-8601 strings so the same arrays serve JSON and Blade.
 */
class PatientSummaryService
{
    /** Every section a caller can be given. */
    public const SECTIONS = ['visits', 'money', 'clinical', 'lab', 'imaging', 'appointments', 'admissions', 'cards', 'insurance'];

    /** The age bands of a patient's debt, in days (upper bound inclusive; the last is open). */
    public const AGE_BANDS = ['0-30' => 30, '31-60' => 60, '61-90' => 90, '90+' => null];

    /**
     * What this person may see of a patient's record: their permissions,
     * narrowed by the practice's modules (a dental clinic has no admissions).
     *
     * @return list<string>
     */
    public static function sectionsFor(User $user, ?Practice $practice = null): array
    {
        $practice ??= Practice::current();
        $can = fn (string $p): bool => $user->can($p);

        $sections = [];
        if ($can('visits.view')) {
            $sections[] = 'visits';
            // The clinical record is for the clinical team (docs/rbac.md): the
            // desk sees that a visit happened and why, not what was found.
            if ($can('visits.clinical')) {
                $sections[] = 'clinical';
                if ($practice->uses('laboratory')) {
                    $sections[] = 'lab';
                }
                if ($practice->uses('radiology')) {
                    $sections[] = 'imaging';
                }
            }
        }
        if ($can('billing.view')) {
            $sections[] = 'money';
        }
        if ($can('appointments.view')) {
            $sections[] = 'appointments';
        }
        if ($can('ipd.view') && $practice->uses('inpatient')) {
            $sections[] = 'admissions';
        }
        if ($can('patients.card.manage') && $practice->uses('cards')) {
            $sections[] = 'cards';
        }
        if ($can('insurance.view') && $practice->uses('insurance')) {
            $sections[] = 'insurance';
        }

        return $sections;
    }

    /**
     * Figures + alerts + the bounded recent lists, for the sections given.
     *
     * @param  list<string>  $sections
     * @return array{figures:array<string,mixed>,alerts:list<array<string,mixed>>,recent:array<string,list<array<string,mixed>>>}
     */
    public function summary(Patient $patient, array $sections, int $limit = 5): array
    {
        $figures = $this->figures($patient, $sections);

        return [
            'figures' => $figures,
            'alerts' => $this->alerts($patient, $sections, $figures),
            'recent' => $this->recent($patient, $sections, $limit),
        ];
    }

    // ── Figures ──────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $sections
     * @return array<string,mixed>
     */
    public function figures(Patient $patient, array $sections): array
    {
        $figures = [];

        if (in_array('money', $sections, true)) {
            $figures += $this->moneyFigures($patient);
        }

        if (in_array('cards', $sections, true)) {
            $cards = PatientCard::query()->spendableBy($patient->id)->where('status', 'active');
            $figures['cards_count'] = (clone $cards)->count();
            $figures['card_balance'] = $figures['cards_count'] > 0
                ? $this->dec((clone $cards)->sum('balance'))
                : null;
        }

        if (in_array('visits', $sections, true)) {
            $agg = Visit::query()->where('patient_id', $patient->id)
                ->selectRaw('count(*) as n, max(created_at) as last_at')->toBase()->first();
            $figures['visits_count'] = (int) ($agg->n ?? 0);
            $figures['last_visit_at'] = $agg?->last_at ? Carbon::parse($agg->last_at)->toIso8601String() : null;

            $open = Visit::query()->where('patient_id', $patient->id)
                ->where('status', '!=', VisitStatus::Completed->value)->latest('id')->first();
            $figures['open_visit'] = $open === null ? null : [
                'uuid' => (string) $open->uuid,
                'visit_no' => (string) $open->visit_no,
                'status' => $open->stateLabel(),
            ];
        }

        if (in_array('appointments', $sections, true)) {
            $next = Appointment::query()->where('patient_id', $patient->id)
                ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value, AppointmentStatus::CheckedIn->value])
                ->where('scheduled_at', '>=', Carbon::now()->startOfDay())
                ->with('doctor:id,name')->orderBy('scheduled_at')->first();
            $figures['next_appointment'] = $next === null ? null : [
                'uuid' => (string) $next->uuid,
                'scheduled_at' => $next->scheduled_at->toIso8601String(),
                'doctor' => $next->doctor?->name,
                'status' => $next->status->label(),
            ];
        }

        if (in_array('admissions', $sections, true)) {
            $now = Admission::query()->where('patient_id', $patient->id)->where('status', 'admitted')
                ->with('bed.ward')->latest('admitted_at')->first();
            $figures['admitted_now'] = $now === null ? null : [
                'uuid' => (string) $now->uuid,
                'ward' => $now->bed?->ward?->name,
                'bed' => $now->bed?->name,
                'since' => $now->admitted_at->toIso8601String(),
            ];
        }

        return $figures;
    }

    /** @return array{outstanding:string,billed_total:string,paid_total:string,outstanding_count:int,oldest_unpaid_days:?int} */
    private function moneyFigures(Patient $patient): array
    {
        // One pass over the account. A void invoice is neither billed nor
        // owed; "outstanding" is the shared Invoice::outstanding() definition,
        // restated here as a CASE only so it is one query rather than three.
        $row = Invoice::query()->where('patient_id', $patient->id)
            ->where('status', '!=', 'void')
            ->selectRaw("coalesce(sum(total),0) as billed, coalesce(sum(amount_paid),0) as paid,
                coalesce(sum(case when status in ('issued','partially_paid') and balance > 0 then balance else 0 end),0) as owed,
                sum(case when status in ('issued','partially_paid') and balance > 0 then 1 else 0 end) as owed_n,
                min(case when status in ('issued','partially_paid') and balance > 0 then created_at end) as oldest")
            ->toBase()->first();

        return [
            'outstanding' => $this->dec($row->owed ?? 0),
            'billed_total' => $this->dec($row->billed ?? 0),
            'paid_total' => $this->dec($row->paid ?? 0),
            'outstanding_count' => (int) ($row->owed_n ?? 0),
            'oldest_unpaid_days' => $row?->oldest ? (int) Carbon::parse($row->oldest)->diffInDays(Carbon::now()) : null,
        ];
    }

    // ── Alerts ───────────────────────────────────────────────────────────

    /**
     * What a person looking at this patient must not miss, most urgent first.
     * Only what applies is returned.
     *
     * @param  list<string>  $sections
     * @param  array<string,mixed>  $figures
     * @return list<array<string,mixed>>
     */
    public function alerts(Patient $patient, array $sections, array $figures): array
    {
        $alerts = [];

        if ($patient->status === PatientStatus::Deceased) {
            $alerts[] = ['key' => 'status', 'tone' => 'danger', 'status' => 'deceased', 'label' => 'Deceased'];
        } elseif ($patient->status === PatientStatus::Inactive) {
            $alerts[] = ['key' => 'status', 'tone' => 'warn', 'status' => 'inactive', 'label' => 'Inactive'];
        }

        $allergies = array_values(array_filter((array) ($patient->allergies ?? [])));
        if ($allergies !== []) {
            $alerts[] = ['key' => 'allergies', 'tone' => 'warn', 'items' => $allergies];
        }

        $chronic = array_values(array_filter((array) ($patient->chronic_conditions ?? [])));
        if ($chronic !== []) {
            $alerts[] = ['key' => 'chronic', 'tone' => 'info', 'items' => $chronic];
        }

        if (bccomp((string) ($figures['outstanding'] ?? '0'), '0', 2) > 0) {
            $alerts[] = [
                'key' => 'owes', 'tone' => 'danger', 'tab' => 'billing',
                'amount' => $figures['outstanding'],
                'invoices' => $figures['outstanding_count'],
                'oldest_unpaid_days' => $figures['oldest_unpaid_days'],
            ];
        }

        if (! empty($figures['admitted_now'])) {
            $alerts[] = ['key' => 'admitted', 'tone' => 'info'] + $figures['admitted_now'];
        }

        if (! $patient->consent_given) {
            $alerts[] = ['key' => 'no_consent', 'tone' => 'warn'];
        }

        if (in_array('insurance', $sections, true)) {
            $claims = $this->claimsAwaiting($patient);
            if ($claims['count'] > 0 || filled($patient->insurance_provider)) {
                $alerts[] = [
                    'key' => 'insurance', 'tone' => 'info',
                    'provider' => $patient->insurance_provider,
                    'member_no' => $patient->insurance_member_no,
                    'claims_awaiting' => $claims['count'],
                    'claims_amount' => $claims['amount'],
                ];
            }
        }

        return $alerts;
    }

    /** @return array{count:int,amount:string} claims raised for this patient and not yet settled */
    public function claimsAwaiting(Patient $patient): array
    {
        $row = InsuranceClaim::query()->where('patient_id', $patient->id)
            ->whereIn('status', [ClaimStatus::Draft->value, ClaimStatus::Submitted->value, ClaimStatus::Approved->value])
            ->selectRaw('count(*) as n, coalesce(sum(amount),0) as total')->toBase()->first();

        return ['count' => (int) ($row->n ?? 0), 'amount' => $this->dec($row->total ?? 0)];
    }

    /**
     * The claims themselves, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function claimRows(Patient $patient, int $limit = 10): array
    {
        return InsuranceClaim::query()->where('patient_id', $patient->id)
            ->whereIn('status', [ClaimStatus::Draft->value, ClaimStatus::Submitted->value, ClaimStatus::Approved->value])
            ->with(['provider:id,name', 'invoice:id,uuid,invoice_no'])->orderBy('created_at')->limit($limit)->get()
            ->map(fn (InsuranceClaim $c) => [
                'uuid' => (string) $c->uuid,
                'claim_no' => (string) $c->claim_no,
                'insurer' => $c->provider?->name,
                'invoice_no' => $c->invoice?->invoice_no,
                'amount' => $this->dec($c->amount),
                'status' => $c->status->label(),
                'status_badge' => $c->status->badge(),
                'date' => $c->created_at->toIso8601String(),
            ])->all();
    }

    // ── Aged debt ────────────────────────────────────────────────────────

    /**
     * Unpaid invoices, aged by when they were raised (the reports' basis).
     *
     * @return array{total:string,count:int,buckets:array<string,string>,invoices:list<array<string,mixed>>}
     */
    public function aging(Patient $patient): array
    {
        $invoices = Invoice::query()->outstanding()->where('patient_id', $patient->id)
            ->orderBy('created_at')->get(['id', 'uuid', 'invoice_no', 'total', 'amount_paid', 'balance', 'status', 'created_at']);

        $buckets = array_fill_keys(array_keys(self::AGE_BANDS), '0.00');
        $total = '0.00';
        $rows = [];
        $now = Carbon::now();

        foreach ($invoices as $invoice) {
            $balance = (string) $invoice->balance;
            $age = (int) $invoice->created_at->diffInDays($now);
            $band = '90+';
            foreach (self::AGE_BANDS as $label => $upTo) {
                if ($upTo !== null && $age <= $upTo) {
                    $band = $label;
                    break;
                }
            }
            $buckets[$band] = bcadd($buckets[$band], $balance, 2);
            $total = bcadd($total, $balance, 2);
            $rows[] = [
                'uuid' => (string) $invoice->uuid,
                'invoice_no' => (string) $invoice->invoice_no,
                'date' => $invoice->created_at->toIso8601String(),
                'status' => $invoice->status->label(),
                'status_badge' => $invoice->status->badge(),
                'total' => $this->dec($invoice->total),
                'amount_paid' => $this->dec($invoice->amount_paid),
                'balance' => $this->dec($balance),
                'age_days' => $age,
                'band' => $band,
            ];
        }

        return ['total' => $total, 'count' => count($rows), 'buckets' => $buckets, 'invoices' => $rows];
    }

    /**
     * The patient's cards and what has moved on them.
     *
     * @return array{balance:string,debt:string,cards:list<array<string,mixed>>}
     */
    public function cardLedger(Patient $patient): array
    {
        $cards = PatientCard::query()->spendableBy($patient->id)->with('provider:id,name')->latest()->limit(10)->get();
        $balance = '0.00';
        $debt = '0.00';
        $rows = [];

        foreach ($cards as $card) {
            if ($card->status->canTransact()) {
                $balance = bcadd($balance, (string) $card->balance, 2);
            }
            $debt = bcadd($debt, $card->debt(), 2);
            $rows[] = [
                'uuid' => (string) $card->uuid,
                'masked' => $card->masked(),
                'status' => $card->status->label(),
                'status_badge' => $card->status->badge(),
                'insurer' => $card->provider?->name,
                'balance' => $this->dec($card->balance),
                'debt' => $card->debt(),
                'spendable' => $card->spendable(),
            ];
        }

        return ['balance' => $balance, 'debt' => $debt, 'cards' => $rows];
    }

    // ── Bounded recent lists ─────────────────────────────────────────────

    /**
     * @param  list<string>  $sections
     * @return array<string,list<array<string,mixed>>>
     */
    public function recent(Patient $patient, array $sections, int $limit = 5): array
    {
        $limit = max(1, min($limit, 25));
        $has = fn (string $s): bool => in_array($s, $sections, true);
        $recent = [];

        if ($has('visits')) {
            $recent['visits'] = $this->visitRows(
                Visit::query()->where('patient_id', $patient->id)->with('doctor:id,name')->latest('created_at')->latest('id')->limit($limit)->get(),
                $has('money'),
                $has('clinical'),
            );
        }
        if ($has('money')) {
            $recent['invoices'] = Invoice::query()->where('patient_id', $patient->id)->latest('created_at')->latest('id')
                ->limit($limit)->get()->map(fn (Invoice $i) => $this->invoiceRow($i))->all();
            $recent['payments'] = Payment::query()->where('patient_id', $patient->id)->with(['invoice:id,uuid,invoice_no', 'receivedBy:id,name'])
                ->latest('created_at')->latest('id')->limit($limit)->get()->map(fn (Payment $p) => $this->paymentRow($p))->all();
        }
        if ($has('clinical')) {
            $recent['diagnoses'] = $this->diagnosisRows($patient, $limit);
            $recent['medication'] = $this->currentMedication($patient, $limit);
            $recent['vitals'] = $this->vitals($patient, $limit);
        }
        if ($has('lab')) {
            $recent['lab_orders'] = $this->labRows($patient, $limit);
        }
        if ($has('imaging')) {
            $recent['imaging_orders'] = $this->imagingRows($patient, $limit);
        }
        if ($has('admissions')) {
            $recent['admissions'] = $this->admissionRows($patient, $limit);
        }
        if ($has('appointments')) {
            $recent['appointments'] = Appointment::query()->where('patient_id', $patient->id)->with('doctor:id,name')
                ->latest('scheduled_at')->limit($limit)->get()->map(fn (Appointment $a) => $this->appointmentRow($a))->all();
        }

        return $recent;
    }

    /**
     * Diagnoses as recorded on visits, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function diagnosisRows(Patient $patient, int $limit = 5): array
    {
        return Visit::query()->where('patient_id', $patient->id)->whereNotNull('diagnosis')->where('diagnosis', '!=', '')
            ->with('doctor:id,name')->latest('created_at')->latest('id')->limit($limit)->get()
            ->map(fn (Visit $v) => [
                'visit_uuid' => (string) $v->uuid, 'visit_no' => (string) $v->visit_no,
                'date' => $v->created_at->toIso8601String(), 'diagnosis' => (string) $v->diagnosis,
                'doctor' => $v->doctor?->name,
            ])->all();
    }

    /**
     * Drugs the patient is on right now: a prescription line whose course
     * (start date + days) has not run out.
     *
     * @return list<array<string,mixed>>
     */
    public function currentMedication(Patient $patient, int $limit = 25): array
    {
        $today = Carbon::today();

        return DoseItem::query()
            ->whereHas('prescription', fn (Builder $q) => $q->where('patient_id', $patient->id))
            ->with('prescription:id,visit_id,prescribed_by,created_at')
            ->where('start_date', '<=', $today->toDateString())
            ->latest('start_date')->limit(100)->get()
            ->filter(fn (DoseItem $d) => $d->start_date->copy()->addDays(max(1, (int) $d->days) - 1)->gte($today))
            ->take($limit)
            ->map(fn (DoseItem $d) => [
                'drug' => (string) $d->drug_name,
                'dosage' => $d->dosage,
                'slots' => (array) $d->slots,
                'instructions' => $d->instructions,
                'start_date' => $d->start_date->toDateString(),
                'ends_on' => $d->start_date->copy()->addDays(max(1, (int) $d->days) - 1)->toDateString(),
                'days' => (int) $d->days,
            ])->values()->all();
    }

    /**
     * Prescriptions written for the patient, newest first, with their lines.
     *
     * @return list<array<string,mixed>>
     */
    public function prescriptions(Patient $patient, int $limit = 10): array
    {
        return \App\Models\Prescription::query()->where('patient_id', $patient->id)
            ->with(['doseItems', 'prescriber:id,name', 'visit:id,uuid,visit_no'])
            ->latest()->latest('id')->limit($limit)->get()
            ->map(fn ($p) => [
                'date' => $p->created_at->toIso8601String(),
                'prescriber' => $p->prescriber?->name,
                'visit_uuid' => $p->visit?->uuid,
                'visit_no' => $p->visit?->visit_no,
                'notes' => $p->notes,
                'lines' => $p->doseItems->map(fn (DoseItem $d) => [
                    'drug' => (string) $d->drug_name, 'dosage' => $d->dosage, 'days' => (int) $d->days,
                ])->all(),
            ])->all();
    }

    /**
     * Vitals as taken on visits, newest first - the trend is the list read downwards.
     *
     * @return list<array<string,mixed>>
     */
    public function vitals(Patient $patient, int $limit = 6): array
    {
        return Visit::query()->where('patient_id', $patient->id)->whereNotNull('vitals_recorded_at')
            ->latest('vitals_recorded_at')->limit($limit)
            ->get(['uuid', 'visit_no', 'temperature', 'weight', 'height', 'bmi', 'pulse', 'respiratory_rate', 'spo2', 'blood_pressure', 'vitals_recorded_at'])
            ->map(fn (Visit $v) => [
                'visit_uuid' => (string) $v->uuid, 'visit_no' => (string) $v->visit_no,
                'recorded_at' => $v->vitals_recorded_at->toIso8601String(),
                'temperature' => $v->temperature, 'weight' => $v->weight, 'height' => $v->height, 'bmi' => $v->bmi,
                'pulse' => $v->pulse, 'respiratory_rate' => $v->respiratory_rate, 'spo2' => $v->spo2,
                'blood_pressure' => $v->blood_pressure,
            ])->all();
    }

    /**
     * Lab orders, each with its result lines and which of them came back abnormal.
     *
     * @return list<array<string,mixed>>
     */
    public function labRows(Patient $patient, int $limit = 5): array
    {
        return LabOrder::query()->where('patient_id', $patient->id)->with(['items', 'orderedBy:id,name', 'visit:id,uuid,visit_no'])
            ->latest('created_at')->latest('id')->limit($limit)->get()
            ->map(function (LabOrder $o) {
                $abnormal = $o->items->filter(fn ($i) => $i->result_flag !== null && $i->result_flag->value !== 'normal')->count();

                return [
                    'uuid' => (string) $o->uuid,
                    'date' => $o->created_at->toIso8601String(),
                    'status' => $o->status->label(),
                    'status_badge' => $o->status->badge(),
                    'ordered_by' => $o->orderedBy?->name,
                    'visit_uuid' => $o->visit?->uuid,
                    'visit_no' => $o->visit?->visit_no,
                    'abnormal' => $abnormal,
                    'tests' => $o->items->map(fn ($i) => [
                        'name' => (string) $i->name,
                        'value' => $i->result_value,
                        'unit' => $i->unit,
                        'range' => $i->reference_range,
                        'flag' => $i->result_flag?->value,
                        'flag_label' => $i->result_flag?->label(),
                        'flag_badge' => $i->result_flag?->badge(),
                    ])->all(),
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    public function imagingRows(Patient $patient, int $limit = 5): array
    {
        return RadiologyOrder::query()->where('patient_id', $patient->id)->with(['items', 'visit:id,uuid,visit_no'])
            ->latest('created_at')->latest('id')->limit($limit)->get()
            ->map(fn (RadiologyOrder $o) => [
                'uuid' => (string) $o->uuid,
                'date' => $o->created_at->toIso8601String(),
                'status' => $o->status->label(),
                'status_badge' => $o->status->badge(),
                'studies' => $o->items->pluck('name')->all(),
                'findings' => $o->findings,
                'impression' => $o->impression,
                'reported_at' => $o->reported_at?->toIso8601String(),
                'visit_uuid' => $o->visit?->uuid,
                'visit_no' => $o->visit?->visit_no,
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    public function admissionRows(Patient $patient, int $limit = 5): array
    {
        return Admission::query()->where('patient_id', $patient->id)->with(['bed.ward', 'admittingDoctor:id,name'])
            ->latest('admitted_at')->limit($limit)->get()
            ->map(fn (Admission $a) => [
                'uuid' => (string) $a->uuid,
                'ward' => $a->bed?->ward?->name,
                'bed' => $a->bed?->name,
                'status' => $a->status->label(),
                'status_badge' => $a->status->badge(),
                'reason' => $a->reason,
                'admitted_at' => $a->admitted_at->toIso8601String(),
                'discharged_at' => $a->discharged_at?->toIso8601String(),
                'nights' => $a->nights(),
                'doctor' => $a->admittingDoctor?->name,
                'discharge_notes' => $a->discharge_notes,
            ])->all();
    }

    // ── Paged lists (the tabs) ───────────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int,array<string,mixed>>
     */
    public function visitsPage(Patient $patient, bool $withMoney, int $perPage = 15, string $pageName = 'visits', bool $withClinical = true): LengthAwarePaginator
    {
        $page = Visit::query()->where('patient_id', $patient->id)->with('doctor:id,name')
            ->latest('created_at')->latest('id')->paginate($perPage, pageName: $pageName);

        return $this->remap($page, $this->visitRows($page->getCollection(), $withMoney, $withClinical));
    }

    /** @return LengthAwarePaginator<int,array<string,mixed>> */
    public function invoicesPage(Patient $patient, int $perPage = 10, string $pageName = 'invoices'): LengthAwarePaginator
    {
        $page = Invoice::query()->where('patient_id', $patient->id)->latest('created_at')->latest('id')->paginate($perPage, pageName: $pageName);

        return $this->remap($page, $page->getCollection()->map(fn (Invoice $i) => $this->invoiceRow($i))->all());
    }

    /** @return LengthAwarePaginator<int,array<string,mixed>> */
    public function paymentsPage(Patient $patient, int $perPage = 10, string $pageName = 'payments'): LengthAwarePaginator
    {
        $page = Payment::query()->where('patient_id', $patient->id)->with(['invoice:id,uuid,invoice_no', 'receivedBy:id,name'])
            ->latest('created_at')->latest('id')->paginate($perPage, pageName: $pageName);

        return $this->remap($page, $page->getCollection()->map(fn (Payment $p) => $this->paymentRow($p))->all());
    }

    /** @return LengthAwarePaginator<int,array<string,mixed>> */
    public function appointmentsPage(Patient $patient, bool $upcoming, int $perPage = 10, string $pageName = 'appointments'): LengthAwarePaginator
    {
        $q = Appointment::query()->where('patient_id', $patient->id)->with(['doctor:id,name', 'department:id,name']);
        $upcoming
            ? $q->where('scheduled_at', '>=', Carbon::now()->startOfDay())->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value, AppointmentStatus::Completed->value])->orderBy('scheduled_at')
            : $q->where(fn (Builder $w) => $w->where('scheduled_at', '<', Carbon::now()->startOfDay())
                ->orWhereIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value, AppointmentStatus::Completed->value]))->orderByDesc('scheduled_at');

        $page = $q->paginate($perPage, pageName: $pageName);

        return $this->remap($page, $page->getCollection()->map(fn (Appointment $a) => $this->appointmentRow($a))->all());
    }

    /**
     * The same page, its models replaced by the rows the views read.
     *
     * @param  Paginator<int,*>  $page
     * @param  list<array<string,mixed>>  $rows
     * @return LengthAwarePaginator<int,array<string,mixed>>
     */
    private function remap(Paginator $page, array $rows): LengthAwarePaginator
    {
        return new Paginator($rows, $page->total(), $page->perPage(), $page->currentPage(), $page->getOptions());
    }

    // ── The statement ────────────────────────────────────────────────────

    /**
     * Everything the printed statement of account carries.
     *
     * @return array{invoices:list<array<string,mixed>>,payments:list<array<string,mixed>>,billed:string,paid:string,balance_due:string,aging:array<string,mixed>}
     */
    public function statement(Patient $patient): array
    {
        $invoices = Invoice::query()->where('patient_id', $patient->id)->where('status', '!=', 'void')
            ->orderBy('created_at')->orderBy('id')->get();
        $payments = Payment::query()->where('patient_id', $patient->id)->with('invoice:id,uuid,invoice_no')
            ->whereHas('invoice', fn (Builder $q) => $q->where('status', '!=', 'void'))
            ->orderBy('created_at')->orderBy('id')->get();

        $money = $this->moneyFigures($patient);

        return [
            'invoices' => $invoices->map(fn (Invoice $i) => $this->invoiceRow($i))->all(),
            'payments' => $payments->map(fn (Payment $p) => $this->paymentRow($p))->all(),
            'billed' => $money['billed_total'],
            'paid' => $money['paid_total'],
            'balance_due' => $money['outstanding'],
            'aging' => $this->aging($patient),
        ];
    }

    // ── Timeline ─────────────────────────────────────────────────────────

    /**
     * The recent lists merged into one line of events, newest first.
     *
     * @param  array<string,list<array<string,mixed>>>  $recent  {@see self::recent()}
     * @return list<array{at:string,kind:string,icon:string,title:string,detail:?string,tab:string,uuid:?string}>
     */
    public function activity(array $recent, int $limit = 20): array
    {
        $events = new Collection;
        $push = function (string $at, string $kind, string $icon, string $title, ?string $detail, string $tab, ?string $uuid = null) use ($events): void {
            $events->push(compact('at', 'kind', 'icon', 'title', 'detail', 'tab', 'uuid'));
        };

        foreach ($recent['visits'] ?? [] as $v) {
            $push($v['date'], 'visit', 'fa-stethoscope', 'Visit '.$v['visit_no'], trim($v['state'].(filled($v['diagnosis'] ?? null) ? ' · '.$v['diagnosis'] : '')), 'visits', $v['uuid']);
        }
        foreach ($recent['invoices'] ?? [] as $i) {
            $push($i['date'], 'invoice', 'fa-file-invoice-dollar', 'Invoice '.$i['invoice_no'], $i['status'], 'billing', $i['uuid']);
        }
        foreach ($recent['payments'] ?? [] as $p) {
            $push($p['date'], 'payment', 'fa-money-bill-wave', 'Payment received', $p['method'].' · '.$p['invoice_no'], 'billing', $p['uuid']);
        }
        foreach ($recent['lab_orders'] ?? [] as $l) {
            $push($l['date'], 'lab', 'fa-flask', 'Lab order', $l['status'].($l['abnormal'] ? ' · '.$l['abnormal'].' abnormal' : ''), 'clinical', $l['uuid']);
        }
        foreach ($recent['imaging_orders'] ?? [] as $r) {
            $push($r['date'], 'imaging', 'fa-x-ray', 'Imaging', implode(', ', $r['studies']).' · '.$r['status'], 'clinical', $r['uuid']);
        }
        foreach ($recent['admissions'] ?? [] as $a) {
            $push($a['admitted_at'], 'admission', 'fa-bed', 'Admitted to '.($a['ward'] ?? 'ward'), $a['status'].' · '.$a['nights'].' night'.($a['nights'] === 1 ? '' : 's'), 'clinical', $a['uuid']);
        }
        foreach ($recent['appointments'] ?? [] as $a) {
            $push($a['scheduled_at'], 'appointment', 'fa-calendar-check', 'Appointment', $a['status'].($a['doctor'] ? ' · '.$a['doctor'] : ''), 'appointments', $a['uuid']);
        }

        return $events->sortByDesc('at')->take($limit)->values()->all();
    }

    // ── Row shapes ───────────────────────────────────────────────────────

    /**
     * @param  iterable<Visit>  $visits
     * @return list<array<string,mixed>>
     */
    private function visitRows(iterable $visits, bool $withMoney, bool $withClinical = true): array
    {
        $visits = collect($visits);
        $totals = collect();

        if ($withMoney && $visits->isNotEmpty()) {
            $totals = Invoice::query()->whereIn('visit_id', $visits->pluck('id'))->where('status', '!=', 'void')
                ->groupBy('visit_id')->selectRaw('visit_id, sum(total) as billed, sum(balance) as owed')->get()->keyBy('visit_id');
        }

        return $visits->map(function (Visit $v) use ($withMoney, $withClinical, $totals) {
            $row = [
                'uuid' => (string) $v->uuid,
                'visit_no' => (string) $v->visit_no,
                'date' => $v->created_at->toIso8601String(),
                'state' => $v->stateLabel(),
                'state_badge' => $v->stateBadge(),
                'doctor' => $v->doctor?->name,
                'reason' => $v->reason,
            ];
            // Omitted, not empty, for somebody outside the clinical team.
            if ($withClinical) {
                $row['diagnosis'] = $v->diagnosis;
            }
            if ($withMoney) {
                $row['billed'] = $this->dec($totals[$v->id]->billed ?? 0);
                $row['balance'] = $this->dec($totals[$v->id]->owed ?? 0);
            }

            return $row;
        })->all();
    }

    /** @return array<string,mixed> */
    private function invoiceRow(Invoice $i): array
    {
        return [
            'uuid' => (string) $i->uuid,
            'invoice_no' => (string) $i->invoice_no,
            'date' => $i->created_at->toIso8601String(),
            'status' => $i->status->label(),
            'status_badge' => $i->status->badge(),
            'total' => $this->dec($i->total),
            'amount_paid' => $this->dec($i->amount_paid),
            'balance' => $this->dec($i->balance),
        ];
    }

    /** @return array<string,mixed> */
    private function paymentRow(Payment $p): array
    {
        return [
            'uuid' => (string) $p->uuid,
            'date' => $p->created_at->toIso8601String(),
            'method' => $p->method->label(),
            'amount' => $this->dec($p->amount),
            'reference' => $p->reference,
            'invoice_uuid' => $p->invoice?->uuid,
            'invoice_no' => $p->invoice?->invoice_no,
            'received_by' => $p->receivedBy?->name,
        ];
    }

    /** @return array<string,mixed> */
    private function appointmentRow(Appointment $a): array
    {
        return [
            'uuid' => (string) $a->uuid,
            'scheduled_at' => $a->scheduled_at->toIso8601String(),
            'status' => $a->status->label(),
            'status_badge' => $a->status->badge(),
            'doctor' => $a->doctor?->name,
            'department' => $a->relationLoaded('department') ? $a->department?->name : null,
            'reason' => $a->reason,
            'follow_up' => $a->isFollowUp(),
        ];
    }

    private function dec(mixed $value): string
    {
        return \App\Support\HospitalSettings::decimal($value, 2);
    }
}
