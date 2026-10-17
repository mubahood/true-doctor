<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\DentalTreatmentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\DentalTreatmentItem;
use App\Models\DentalXray;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use App\Support\CaseSheet\DentalCaseSheet;
use App\Support\HospitalSettings;
use App\Support\Practice\Practice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The dental case sheet on a visit (docs/dental.md, "The case sheet"): one
 * place that assembles it for the web, the app and the printed report, and
 * the dental rules around it: a diagnosis per tooth that also marks the chart,
 * an X-ray taken at the chair (billed and filed like any other work), the
 * instructions handed to the patient, and the review appointment.
 *
 * The history and examination sections are CaseSheetService's; the chart and
 * the plan are DentalService's. This class adds no second copy of either.
 */
class DentalCaseSheetService
{
    public function __construct(
        private readonly CaseSheetService $sections,
        private readonly DentalService $dental,
        private readonly DiagnosisService $diagnoses,
        private readonly OrderService $orders,
        private readonly OrderAttachmentService $attachments,
        private readonly AppointmentService $appointments,
        private readonly VisitService $visits,
    ) {}

    /**
     * Whether a visit gets the dental case sheet instead of the general note:
     * in a dental clinic, always; in a hospital that also does dentistry, for
     * a visit in a dental or orthodontic department.
     */
    public static function appliesTo(Visit $visit, ?Hospital $hospital): bool
    {
        if (! DentalService::enabledFor($hospital)) {
            return false;
        }
        if (Practice::of($hospital)->sector->key === 'dental_clinic') {
            return true;
        }

        // Whole words: "Accident and Emergency" and "Orthopaedics" are not dentistry.
        return preg_match('/\b(dental|dentist|dentistry|orthodont\w*)\b/i', (string) $visit->department?->name) === 1;
    }

    /** Who may read a case sheet: whoever may read the chart. */
    public static function canRead(?User $user): bool
    {
        return $user !== null && ($user->can('treatments.view') || $user->can('visits.diagnose'));
    }

    // ── The whole sheet ────────────────────────────────────────────────

    /**
     * Everything on the sheet, in the order a dentist works. The financial
     * history is left out for someone who may not see money.
     *
     * @return array<string,mixed>
     */
    public function sheet(Visit $visit, ?User $viewer): array
    {
        $patient = $visit->patient()->withTrashed()->firstOrFail();
        $sections = $this->sections->sections($visit);
        $history = $this->sections->patientHistory($patient);
        $withMoney = $viewer !== null && $viewer->can('billing.view');

        $sectionRows = [];
        foreach (DentalCaseSheet::sections() as $key => $definition) {
            if ($key === 'financial' && ! $withMoney) {
                continue;
            }
            $row = $definition['scope'] === 'visit'
                ? ($sections[$key] ?? ['data' => [], 'version' => 0, 'recorded_at' => null, 'recorded_by' => null])
                : $history[$key];
            $sectionRows[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'group' => $definition['group'],
                'scope' => $definition['scope'],
                'has_normal' => ! empty($definition['normal']),
                'data' => $row['data'],
                'lines' => CaseSheetService::lines($key, $row['data']),
                'version' => $row['version'],
                'recorded_at' => $row['recorded_at'],
                'recorded_by' => $row['recorded_by'],
            ] + ($definition['scope'] === 'patient' ? [
                'confirmed' => $row['confirmed'] ?? false,
                'asked_this_visit' => $this->sections->confirmedOn($patient, $key, $visit),
            ] : []);
        }

        return [
            'template' => DentalCaseSheet::TEMPLATE,
            'groups' => DentalCaseSheet::GROUPS,
            'sections' => $sectionRows,
            'financial' => $withMoney ? $this->financial($patient) : null,
            // A closed visit reprints the mouth as it was when it closed.
            'chart' => $this->dental->chart($patient, $visit->isOpen() ? null : $visit->completed_at),
            'xrays' => $this->xrays($visit),
            'labs' => $this->labs($visit),
            'diagnoses' => $this->diagnosisRows($visit),
            'done_today' => $this->doneToday($visit),
            'plan' => $this->dental->quote($patient)['items']->map(fn (DentalTreatmentItem $i) => [
                'uuid' => $i->uuid,
                'procedure' => $i->service->name ?? 'Dental procedure',
                'tooth' => $i->tooth,
                'surfaces' => $i->surfaces,
                'status' => $i->status->value,
                'status_label' => $i->status->label(),
                'price' => (string) $i->price,
                'price_label' => HospitalSettings::money($i->price),
            ])->values()->all(),
            'instructions' => $this->instructions($sections['instructions']['data'] ?? []),
            'suggested_instructions' => $this->suggestedInstructions($visit),
            'review' => $this->reviewSuggestion($visit),
            'reviews_booked' => $this->reviewsBooked($visit),
            'next_recall_on' => $patient->next_recall_on?->toDateString(),
        ];
    }

    // ── Diagnosis ──────────────────────────────────────────────────────

    /**
     * A diagnosis from the short dental list, on a tooth or on the mouth: a
     * coded diagnosis the Ministry reports count, and a mark on the chart.
     */
    public function addDiagnosis(Visit $visit, string $key, ?int $tooth, ?string $surfaces, User $by, bool $suspected = false): VisitDiagnosis
    {
        $item = DentalCaseSheet::DIAGNOSES[$key] ?? null;
        if ($item === null) {
            throw ValidationException::withMessages(['condition' => 'Choose a diagnosis from the list.']);
        }
        if ($item['tooth'] === true && $tooth === null) {
            throw ValidationException::withMessages(['tooth' => 'Choose the tooth.']);
        }
        if ($item['tooth'] === false) {
            $tooth = null;
        }
        if ($tooth !== null && ! DentalService::validTooth($tooth)) {
            throw ValidationException::withMessages(['tooth' => "{$tooth} is not a tooth number (FDI: 11 to 48, or 51 to 85 for a child's teeth)."]);
        }

        return DB::transaction(function () use ($visit, $item, $tooth, $surfaces, $by, $suspected) {
            $diagnosis = $this->diagnoses->add($visit, $item['code'], false, $suspected ? 'suspected' : 'confirmed', $item['label'], $by, null, $tooth, $surfaces);

            if ($tooth !== null) {
                $patient = $visit->patient()->firstOrFail();
                $this->dental->record($patient, [[
                    'tooth' => $tooth,
                    'finding' => $item['chart'] ?? 'note',
                    'surfaces' => $surfaces,
                    'notes' => $item['chart'] === null ? $item['label'] : null,
                ]], $visit, $by);
            }
            $this->mirrorDiagnosis($visit);

            return $diagnosis;
        });
    }

    /** A diagnosis from the full list (not the dental short list), on a tooth or on none. */
    public function addCoded(Visit $visit, string $code, ?int $tooth, ?string $surfaces, User $by, bool $suspected = false): VisitDiagnosis
    {
        return DB::transaction(function () use ($visit, $code, $tooth, $surfaces, $by, $suspected) {
            $diagnosis = $this->diagnoses->add($visit, $code, false, $suspected ? 'suspected' : 'confirmed', null, $by, null, $tooth, $surfaces);
            $this->mirrorDiagnosis($visit);

            return $diagnosis;
        });
    }

    public function removeDiagnosis(Visit $visit, int $diagnosisId, User $by): void
    {
        $diagnosis = $this->diagnoses->current($visit)->firstWhere('id', $diagnosisId);
        if ($diagnosis === null) {
            throw ValidationException::withMessages(['diagnosis' => 'That diagnosis is not on this visit.']);
        }
        DB::transaction(function () use ($visit, $diagnosis, $by) {
            $this->diagnoses->remove($diagnosis, $by);
            $this->mirrorDiagnosis($visit);
        });
    }

    /** @return list<array{id:int, uuid:string, code:string, title:string, label:string, tooth:?int, surfaces:?string, primary:bool, suspected:bool}> */
    public function diagnosisRows(Visit $visit): array
    {
        return $this->diagnoses->current($visit)->map(fn (VisitDiagnosis $d) => [
            'id' => $d->id,
            'uuid' => $d->uuid,
            'code' => $d->code,
            'title' => $d->title,
            'label' => $d->note ?: $d->title,
            'tooth' => $d->tooth,
            'surfaces' => $d->surfaces,
            'primary' => $d->is_primary,
            'suspected' => $d->certainty === 'suspected',
        ])->values()->all();
    }

    /**
     * The written diagnosis follows the coded ones ("Caries, dentine (35 MO);
     * Gingivitis"), so every list and older report that reads the visit's
     * diagnosis shows the same thing and nobody types it twice.
     */
    private function mirrorDiagnosis(Visit $visit): void
    {
        $text = collect($this->diagnosisRows($visit))->map(fn (array $d) => $d['label'].($d['tooth'] !== null ? ' ('.$d['tooth'].($d['surfaces'] ? ' '.$d['surfaces'] : '').')' : ''))->implode('; ');
        if ($text !== '') { // nothing coded any more: what was written stays
            $this->visits->updateClinical($visit, ['diagnosis' => mb_substr($text, 0, 2000)]);
        }
    }

    // ── Investigations ─────────────────────────────────────────────────

    /**
     * An X-ray taken at the chair, with no radiology order first. It is
     * billed the way any work is (an imaging order on the visit, with the
     * price-list line on it) and its image is filed with that order, so the
     * bill, the visit report and the file store all see it.
     *
     * @param  list<int|string>  $teeth
     */
    public function recordXray(Visit $visit, string $kind, array $teeth, string $finding, ?Service $service, ?UploadedFile $image, User $by, ?string $uuid = null): DentalXray
    {
        if ($uuid !== null && ($existing = DentalXray::query()->where('uuid', $uuid)->first()) !== null) {
            return $existing; // the same X-ray sent again, even after the visit closed
        }
        $teeth = array_values(array_unique(array_map('intval', array_filter($teeth, fn ($t) => $t !== ''))));
        $finding = trim($finding);
        $errors = [];
        if (! array_key_exists($kind, DentalCaseSheet::XRAY_KINDS)) {
            $errors['kind'] = ['Choose the kind of X-ray.'];
        }
        foreach ($teeth as $t) {
            if (! DentalService::validTooth($t)) {
                $errors['teeth'] = ["{$t} is not a tooth number."];
            }
        }
        if ($teeth === [] && $kind !== 'opg') {
            $errors['teeth'] = ['Say which teeth the X-ray shows.'];
        }
        if ($finding === '') {
            $errors['finding'] = ['Write what the X-ray shows.'];
        } elseif (mb_strlen($finding) > 500) {
            $errors['finding'] = ['Keep the finding under 500 characters.'];
        }
        if (! $visit->isOpen()) {
            $errors['visit'] = ['This visit is closed. An X-ray is recorded on the open visit.'];
        }
        if ($service !== null && ! $service->is_active) {
            $errors['service'] = ['That X-ray is no longer on the price list.'];
        }
        if ($service !== null && $visit->invoices()->where('status', '!=', \App\Enums\InvoiceStatus::Void->value)->exists()) {
            $errors['service'] = ['This visit has been invoiced, so nothing more can be charged on it. Record the X-ray as not charged, or open a new visit.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $xray = DB::transaction(function () use ($visit, $kind, $teeth, $finding, $service, $by, $uuid) {
            Visit::query()->whereKey($visit->id)->lockForUpdate()->first();
            if ($uuid !== null && ($existing = DentalXray::query()->where('uuid', $uuid)->first()) !== null) {
                return $existing; // two copies of the same send, one after the other
            }

            $xray = DentalXray::query()->create([
                'uuid' => $uuid ?? (string) Str::uuid(),
                'patient_id' => $visit->patient_id,
                'visit_id' => $visit->id,
                'kind' => $kind,
                'teeth' => $teeth === [] ? null : $teeth,
                'finding' => $finding,
                'service_id' => $service?->id,
                'recorded_by' => $by->id,
                'taken_at' => Carbon::now(),
            ]);

            $order = $this->orders->place($visit, OrderType::Imaging, $xray->title(), ['subject' => $xray, 'assigned_to' => $by->id], $by->id);
            if ($service !== null) {
                $this->orders->addServiceItem($order, $service, '1', $by->id);
            }
            $this->orders->saveReport($order, $finding);
            $this->orders->transition($order, OrderStatus::Completed, $by->id);

            $xray->forceFill(['order_id' => $order->id])->save();

            return $xray;
        });

        // The image is filed once the record is safely written, so a refused
        // or rolled-back X-ray never leaves a file behind on the disk.
        if ($image !== null && $xray->wasRecentlyCreated && ($order = Order::query()->find($xray->order_id)) !== null) {
            $this->attachments->store($order, $image, $by->id);
        }

        return $xray;
    }

    /** @return list<array<string,mixed>> */
    public function xrays(Visit $visit): array
    {
        return DentalXray::query()->with(['order.attachments', 'recordedBy:id,name'])
            ->where('visit_id', $visit->id)->orderBy('taken_at')->orderBy('id')->get()
            ->map(function (DentalXray $x) {
                $image = $x->order?->attachments->first();

                return [
                    'uuid' => $x->uuid,
                    'kind' => $x->kind,
                    'kind_label' => $x->kindLabel(),
                    'teeth' => $x->teeth ?? [],
                    'finding' => $x->finding,
                    'taken_at' => $x->taken_at->toIso8601String(),
                    'recorded_by' => $x->recordedBy?->name,
                    'billed' => $x->service_id !== null,
                    'order_uuid' => $x->order?->uuid,
                    'image' => $image === null ? null : [
                        'uuid' => $image->uuid,
                        'name' => $image->original_name,
                        'mime' => $image->mime,
                        'url' => route('admin.orders.attachments.download', [$x->order, $image]),
                    ],
                ];
            })->values()->all();
    }

    /** The X-ray services on the price list, the likeliest first. @return Collection<int, Service> */
    public function xrayServices(): Collection
    {
        return Service::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', '%x-ray%')->orWhere('name', 'like', '%xray%')->orWhere('name', 'like', '%OPG%')->orWhere('name', 'like', '%radiograph%'))
            ->orderByRaw("CASE WHEN category LIKE '%dent%' OR name LIKE '%dental%' THEN 0 ELSE 1 END")->orderBy('name')
            ->get(['id', 'name', 'price', 'max_price', 'category']);
    }

    /** The price-list line that fits a kind of X-ray, if there is one. */
    public function serviceFor(string $kind): ?Service
    {
        $words = match ($kind) {
            'opg' => ['opg', 'panoramic'],
            'bitewing' => ['bitewing', 'periapical', 'dental x-ray'],
            default => [$kind, 'dental x-ray'],
        };
        $services = $this->xrayServices();
        foreach ($words as $w) {
            $hit = $services->first(fn (Service $s) => str_contains(mb_strtolower($s->name), $w));
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** The lab tests on the visit with their results. @return list<array<string,mixed>> */
    public function labs(Visit $visit): array
    {
        $rows = [];
        foreach ($visit->labOrders()->with('items')->oldest('id')->get() as $order) {
            foreach ($order->items as $item) {
                $rows[] = [
                    'name' => $item->name,
                    'result' => $item->result_value,
                    'unit' => $item->unit,
                    'flag' => $item->result_flag?->label(),
                    'status' => $order->status->label(),
                ];
            }
        }

        return $rows;
    }

    // ── Treatment and plan ─────────────────────────────────────────────

    /** The procedures finished on this visit, as billed. @return list<array<string,mixed>> */
    public function doneToday(Visit $visit): array
    {
        return DentalTreatmentItem::query()->with(['service:id,name', 'doneBy:id,name'])
            ->where('done_visit_id', $visit->id)->where('status', DentalTreatmentStatus::Done->value)
            ->orderBy('done_at')->get()
            ->map(fn (DentalTreatmentItem $i) => [
                'uuid' => $i->uuid,
                'procedure' => $i->service->name ?? 'Dental procedure',
                'tooth' => $i->tooth,
                'surfaces' => $i->surfaces,
                'notes' => $i->notes,
                'price' => (string) $i->price,
                'price_label' => HospitalSettings::money($i->price),
                'done_by' => $i->doneBy?->name,
                'done_at' => $i->done_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * The instructions given, with their words, for the screen, the report
     * and the patient's printout.
     *
     * @param  array<string,mixed>  $data
     * @return list<array{key:string, label:string, lines:list<string>}>
     */
    public function instructions(array $data): array
    {
        $out = [];
        foreach ((array) ($data['templates'] ?? []) as $key) {
            if (isset(DentalCaseSheet::INSTRUCTIONS[$key])) {
                $out[] = ['key' => (string) $key] + DentalCaseSheet::INSTRUCTIONS[$key];
            }
        }
        if (filled($data['extra'] ?? null)) {
            $out[] = ['key' => 'extra', 'label' => 'Also', 'lines' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $data['extra']) ?: [])))];
        }

        return $out;
    }

    /** The instructions today's work calls for, to tick before the dentist does. @return list<string> */
    public function suggestedInstructions(Visit $visit): array
    {
        return collect($this->doneToday($visit))->map(fn (array $i) => DentalCaseSheet::instructionFor($i['procedure']))
            ->filter()->unique()->values()->all();
    }

    /**
     * When to see the patient again, from what was done today (else what is
     * planned on this visit): an extraction suggests a week.
     *
     * @return array{days:int, on:string, why:string, procedure:string}|null
     */
    public function reviewSuggestion(Visit $visit): ?array
    {
        $names = collect($this->doneToday($visit))->pluck('procedure');
        if ($names->isEmpty()) {
            $names = DentalTreatmentItem::query()->with('service:id,name')
                ->where('visit_id', $visit->id)->whereIn('status', [DentalTreatmentStatus::Planned->value, DentalTreatmentStatus::Accepted->value, DentalTreatmentStatus::InProgress->value])
                ->get()->map(fn (DentalTreatmentItem $i) => $i->service->name ?? '');
        }
        foreach ($names as $name) {
            if (($review = DentalCaseSheet::reviewFor((string) $name)) !== null) {
                return $review + ['on' => Carbon::today()->addDays($review['days'])->toDateString(), 'procedure' => (string) $name];
            }
        }

        return null;
    }

    /** @return list<array{uuid:string, scheduled_at:string, doctor:?string, status:string, reason:?string}> */
    public function reviewsBooked(Visit $visit): array
    {
        return Appointment::query()->with('doctor:id,name')
            ->where('origin_visit_id', $visit->id)
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->orderBy('scheduled_at')->get()
            ->map(fn (Appointment $a) => [
                'uuid' => $a->uuid,
                'scheduled_at' => $a->scheduled_at->toIso8601String(),
                'doctor' => $a->doctor?->name,
                'status' => $a->status->label(),
                'reason' => $a->reason,
            ])->values()->all();
    }

    /**
     * Book the review from the case sheet: with the visit's dentist (or the
     * one booking), carrying the visit it follows. The slot rules are the
     * appointment book's own; a taken or closed slot is said in plain words.
     */
    public function bookReview(Visit $visit, string $date, string $time, User $by, ?int $doctorId = null, ?string $reason = null, int $minutes = 30): Appointment
    {
        $doctorId ??= $visit->doctor_user_id ?? $by->id;
        try {
            $at = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time);
        } catch (\Throwable) {
            $at = null;
        }
        if (! $at instanceof Carbon) {
            throw ValidationException::withMessages(['review' => 'Choose a day and a time.']);
        }
        if ($at->isPast()) {
            throw ValidationException::withMessages(['review' => 'A review is in the future.']);
        }

        try {
            return $this->appointments->book([
                'patient_id' => $visit->patient_id,
                'origin_visit_id' => $visit->id,
                'doctor_user_id' => $doctorId,
                'department_id' => $visit->department_id,
                'scheduled_at' => $at->format('Y-m-d H:i:s'),
                'duration_minutes' => $minutes,
                'source' => \App\Enums\AppointmentSource::WalkIn->value, // a follow-up is told by its origin visit
                'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : 'Dental review',
            ], $by->id);
        } catch (SlotUnavailableException $e) {
            throw ValidationException::withMessages(['review' => $e->getMessage()]);
        }
    }

    // ── Financial history ──────────────────────────────────────────────

    /**
     * Who pays and how, read from billing rather than typed again: what is
     * owed from before, the last payments, how they usually pay, insurance,
     * the quote accepted for this course of treatment, and the arrangement.
     *
     * @return array<string,mixed>
     */
    public function financial(Patient $patient): array
    {
        $owed = Invoice::query()->outstanding()->where('patient_id', $patient->id);
        $outstanding = (string) (clone $owed)->sum('balance');
        $oldest = (clone $owed)->min('created_at');

        $payments = Payment::query()->where('patient_id', $patient->id)->latest('created_at')->latest('id')->limit(30)->get();
        $usual = $payments->countBy(fn (Payment $p) => $p->method->label())->sortDesc()->keys()->first();

        $insurance = PatientInsurance::query()->with('provider:id,name')->where('patient_id', $patient->id)->where('is_active', true)->get()
            ->filter(fn (PatientInsurance $i) => $i->isValid())->map(fn (PatientInsurance $i) => $i->provider?->name)->filter()->values()->all();
        if ($insurance === [] && filled($patient->getAttribute('insurance_provider'))) {
            $insurance = [(string) $patient->getAttribute('insurance_provider')];
        }

        $accepted = DentalTreatmentItem::query()->where('patient_id', $patient->id)
            ->whereIn('status', [DentalTreatmentStatus::Accepted->value, DentalTreatmentStatus::InProgress->value])->get();
        $acceptedTotal = $accepted->reduce(fn (string $s, DentalTreatmentItem $i) => bcadd($s, (string) $i->price, 2), '0.00');

        return [
            'outstanding' => HospitalSettings::decimal($outstanding, 2),
            'outstanding_label' => HospitalSettings::money($outstanding),
            'oldest_unpaid_days' => $oldest ? (int) Carbon::parse($oldest)->diffInDays(Carbon::now()) : null,
            'last_payments' => $payments->take(3)->map(fn (Payment $p) => [
                'date' => $p->created_at->toIso8601String(),
                'amount' => HospitalSettings::decimal($p->amount, 2),
                'amount_label' => HospitalSettings::money($p->amount),
                'method' => $p->method->label(),
            ])->values()->all(),
            'usual_method' => $usual,
            'insurance' => $insurance,
            'accepted_quote' => $accepted->isEmpty() ? null : ['items' => $accepted->count(), 'total' => $acceptedTotal, 'total_label' => HospitalSettings::money($acceptedTotal)],
        ];
    }
}
