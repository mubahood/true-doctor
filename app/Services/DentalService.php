<?php

namespace App\Services;

use App\Enums\DentalFinding;
use App\Enums\DentalTreatmentStatus;
use App\Enums\OrderType;
use App\Models\DentalChartEntry;
use App\Models\DentalTreatmentItem;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Support\Practice\Practice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The dental chart and the treatment plan (roadmap 04 §3): the rules, for the
 * web, the API and the app alike.
 *
 * Teeth are FDI: permanent 11-18, 21-28, 31-38, 41-48; primary 51-55, 61-65,
 * 71-75, 81-85. Surfaces are M (mesial), O (occlusal), D (distal), B (buccal),
 * L (lingual or palatal), I (incisal). The chart is never edited: every
 * finding is a new entry, and the state on a date is the latest entry per
 * tooth and surface up to then. A finding that concerns the whole tooth
 * (missing, crown, implant...) replaces what was on its surfaces.
 */
class DentalService
{
    public const SURFACES = ['M', 'O', 'D', 'B', 'L', 'I'];

    public const RECALL_MONTHS = 6;

    public function __construct(private readonly OrderService $orders) {}

    /** Whether a hospital practises dentistry (the dental pack is on). */
    public static function enabledFor(?Hospital $hospital): bool
    {
        return $hospital !== null && in_array('dental', Practice::of($hospital)->packKeys(), true);
    }

    /** @return list<int> every valid FDI tooth number */
    public static function teeth(bool $primary = false): array
    {
        $out = [];
        foreach ($primary ? [5, 6, 7, 8] : [1, 2, 3, 4] as $quadrant) {
            foreach (range(1, $primary ? 5 : 8) as $n) {
                $out[] = $quadrant * 10 + $n;
            }
        }

        return $out;
    }

    public static function validTooth(int $tooth): bool
    {
        return in_array($tooth, self::teeth(), true) || in_array($tooth, self::teeth(true), true);
    }

    /** "MO", "mod" or "M,O" → "MO" in the canonical order; null for none. */
    public static function normaliseSurfaces(?string $surfaces): ?string
    {
        $letters = array_unique(str_split(strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $surfaces) ?? '')));
        $letters = array_values(array_filter(self::SURFACES, fn ($s) => in_array($s, $letters, true)));

        return $letters === [] ? null : implode('', $letters);
    }

    // ── The chart ──────────────────────────────────────────────────────

    /**
     * The chart as it stood at $asOf (now when null): per tooth, the
     * whole-tooth finding and the finding on each surface, newest first.
     *
     * @return array<int, array{tooth:int, finding:?string, label:?string, tone:?string, mobility:?int, surfaces:array<string,string>, notes:list<string>, updated_at:?string}>
     */
    public function chart(Patient $patient, ?Carbon $asOf = null): array
    {
        $entries = DentalChartEntry::query()
            ->where('patient_id', $patient->id)
            ->when($asOf !== null, fn ($q) => $q->where('recorded_at', '<=', $asOf))
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $teeth = [];
        foreach ($entries as $entry) {
            $t = $entry->tooth;
            $teeth[$t] ??= ['tooth' => $t, 'finding' => null, 'label' => null, 'tone' => null, 'mobility' => null, 'surfaces' => [], 'notes' => [], 'updated_at' => null];

            if ($entry->finding === DentalFinding::Note) {
                if ($entry->notes) {
                    $teeth[$t]['notes'][] = $entry->notes;
                }
            } elseif ($entry->finding->wholeTooth() || $entry->surfaces === null) {
                // A whole-tooth finding (or one with no surface) is the tooth's
                // state now; "sound" clears the tooth and its surfaces.
                $sound = $entry->finding === DentalFinding::Sound;
                $teeth[$t]['finding'] = $sound ? null : $entry->finding->value;
                $teeth[$t]['label'] = $sound ? null : $entry->finding->label();
                $teeth[$t]['tone'] = $sound ? null : $entry->finding->tone();
                $teeth[$t]['mobility'] = $entry->finding === DentalFinding::Mobility ? $entry->mobility : null;
                if ($sound || in_array($entry->finding, [DentalFinding::Missing, DentalFinding::Implant, DentalFinding::Crown], true)) {
                    $teeth[$t]['surfaces'] = [];
                }
            } else {
                foreach (str_split($entry->surfaces) as $s) {
                    $teeth[$t]['surfaces'][$s] = $entry->finding->value;
                }
                // A filled or carious surface means the tooth is present.
                if (in_array($teeth[$t]['finding'], ['missing', 'to_extract'], true) && $entry->finding === DentalFinding::Filled) {
                    $teeth[$t]['finding'] = $teeth[$t]['label'] = $teeth[$t]['tone'] = null;
                }
            }
            if ($entry->notes && $entry->finding !== DentalFinding::Note) {
                $teeth[$t]['notes'][] = $entry->notes;
            }
            $teeth[$t]['updated_at'] = $entry->recorded_at->toIso8601String();
        }

        ksort($teeth);

        return $teeth;
    }

    /** @return Collection<int, DentalChartEntry> the full history, newest first */
    public function history(Patient $patient, int $limit = 200): Collection
    {
        return DentalChartEntry::query()->with('recordedBy:id,name')
            ->where('patient_id', $patient->id)
            ->orderByDesc('recorded_at')->orderByDesc('id')
            ->limit($limit)->get();
    }

    /**
     * Record findings. Each item: tooth, finding, optional surfaces, mobility
     * and notes, and the device's `uuid` when it came from the app (a second
     * copy of the same uuid is ignored, so a retry never charts twice).
     *
     * @param  list<array<string,mixed>>  $findings
     * @return list<DentalChartEntry>
     */
    public function record(Patient $patient, array $findings, ?Visit $visit, User $by): array
    {
        $clean = [];
        $errors = [];
        foreach ($findings as $i => $f) {
            $tooth = (int) ($f['tooth'] ?? 0);
            $finding = DentalFinding::tryFrom((string) ($f['finding'] ?? ''));
            if (! self::validTooth($tooth)) {
                $errors["findings.$i.tooth"] = ["{$tooth} is not a tooth number (FDI: 11 to 48, or 51 to 85 for a child's teeth)."];
            }
            if ($finding === null) {
                $errors["findings.$i.finding"] = ['Choose what was found.'];
            }
            $mobility = isset($f['mobility']) && $f['mobility'] !== '' ? (int) $f['mobility'] : null;
            if ($finding === DentalFinding::Mobility && ($mobility === null || $mobility < 0 || $mobility > 3)) {
                $errors["findings.$i.mobility"] = ['Mobility is graded 0 to 3.'];
            }
            $notes = trim((string) ($f['notes'] ?? ''));
            if ($finding === DentalFinding::Note && $notes === '') {
                $errors["findings.$i.notes"] = ['Write the note.'];
            }
            if (mb_strlen($notes) > 500) {
                $errors["findings.$i.notes"] = ['Keep a note under 500 characters.'];
            }
            $clean[] = [
                'uuid' => isset($f['uuid']) && Str::isUuid((string) $f['uuid']) ? (string) $f['uuid'] : (string) Str::uuid(),
                'tooth' => $tooth,
                'finding' => $finding,
                'surfaces' => $finding !== null && $finding->wholeTooth() ? null : self::normaliseSurfaces($f['surfaces'] ?? null),
                'mobility' => $finding === DentalFinding::Mobility ? $mobility : null,
                'notes' => $notes !== '' ? $notes : null,
            ];
        }
        if ($findings === []) {
            $errors['findings'] = ['Record at least one finding.'];
        }
        if ($visit !== null && $visit->patient_id !== $patient->id) {
            $errors['visit'] = ['That visit is another patient\'s.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($clean, $patient, $visit, $by) {
            $now = now();
            $made = [];
            foreach ($clean as $c) {
                $existing = DentalChartEntry::query()->where('uuid', $c['uuid'])->first();
                if ($existing !== null) {
                    $made[] = $existing;

                    continue;
                }
                $made[] = DentalChartEntry::create([
                    'uuid' => $c['uuid'],
                    'patient_id' => $patient->id,
                    'visit_id' => $visit?->id,
                    'tooth' => $c['tooth'],
                    'surfaces' => $c['surfaces'],
                    'finding' => $c['finding'],
                    'mobility' => $c['mobility'],
                    'notes' => $c['notes'],
                    'recorded_by' => $by->id,
                    'recorded_at' => $now,
                    'created_at' => $now,
                ]);
            }

            return $made;
        });
    }

    // ── The treatment plan ─────────────────────────────────────────────

    /** @return Collection<int, DentalTreatmentItem> open items first, then the rest, newest first */
    public function plan(Patient $patient): Collection
    {
        return DentalTreatmentItem::query()->with(['service:id,name,price,max_price', 'plannedBy:id,name', 'doneBy:id,name'])
            ->where('patient_id', $patient->id)
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'accepted' THEN 1 WHEN 'planned' THEN 2 WHEN 'done' THEN 3 ELSE 4 END")
            ->orderByDesc('id')
            ->get();
    }

    /** Plan a procedure from the price list. A price inside the service's range, or its price. */
    public function planItem(Patient $patient, Service $service, ?int $tooth, ?string $surfaces, string|int|float|null $price, ?string $notes, ?Visit $visit, User $by): DentalTreatmentItem
    {
        if ($tooth !== null && ! self::validTooth($tooth)) {
            throw ValidationException::withMessages(['tooth' => ["{$tooth} is not a tooth number."]]);
        }
        if (! $service->is_active) {
            throw ValidationException::withMessages(['service' => ['That procedure is no longer on the price list.']]);
        }
        try {
            $charge = $service->chargePrice($price);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['price' => [$e->getMessage()]]);
        }

        return DentalTreatmentItem::create([
            'uuid' => (string) Str::uuid(),
            'patient_id' => $patient->id,
            'visit_id' => $visit?->id,
            'service_id' => $service->id,
            'tooth' => $tooth,
            'surfaces' => self::normaliseSurfaces($surfaces),
            'price' => $charge,
            'status' => DentalTreatmentStatus::Planned,
            'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 500) : null,
            'planned_by' => $by->id,
        ]);
    }

    /**
     * Move a procedure on. Done needs an open visit to bill it on: the charge
     * goes through OrderService exactly once, under a row lock, so two taps
     * (or two devices) can never bill it twice.
     */
    public function transition(DentalTreatmentItem $item, DentalTreatmentStatus $to, User $by, ?Visit $visit = null): DentalTreatmentItem
    {
        return DB::transaction(function () use ($item, $to, $by, $visit) {
            /** @var DentalTreatmentItem $locked */
            $locked = DentalTreatmentItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $to) {
                return $locked; // a repeat of the same move changes nothing
            }
            if (! in_array($to, $locked->status->transitionsTo(), true)) {
                throw ValidationException::withMessages(['status' => ["A procedure that is {$locked->status->label()} cannot become {$to->label()}."]]);
            }

            $now = now();
            if ($to === DentalTreatmentStatus::Done) {
                $visit ??= $this->openVisitFor($locked);
                if ($visit === null || $visit->patient_id !== $locked->patient_id) {
                    throw ValidationException::withMessages(['visit' => ['Open a visit for this patient first: the procedure is billed on it.']]);
                }
                if (! $visit->status->isOpen()) {
                    throw ValidationException::withMessages(['visit' => ['That visit is closed. Open a new visit to bill this procedure.']]);
                }
                $service = Service::query()->findOrFail($locked->service_id);
                $order = $this->orders->forSubject($locked, $visit, OrderType::Procedure, $this->title($locked, $service), $by->id);
                $line = $this->orders->addServiceItem($order, $service, '1', $by->id, (string) $locked->price);

                $locked->forceFill([
                    'status' => $to, 'done_at' => $now, 'done_by' => $by->id,
                    'done_visit_id' => $visit->id, 'order_item_id' => $line->id,
                    'accepted_at' => $locked->accepted_at ?? $now, 'started_at' => $locked->started_at ?? $now,
                ])->save();

                return $locked->refresh();
            }

            $locked->forceFill(match ($to) {
                DentalTreatmentStatus::Accepted => ['status' => $to, 'accepted_at' => $now],
                DentalTreatmentStatus::InProgress => ['status' => $to, 'started_at' => $now, 'accepted_at' => $locked->accepted_at ?? $now],
                DentalTreatmentStatus::Cancelled => ['status' => $to, 'cancelled_at' => $now],
                default => ['status' => $to],
            })->save();

            return $locked->refresh();
        });
    }

    /** "Filling (36 MO)" — what the bill and the quote call it. */
    public function title(DentalTreatmentItem $item, ?Service $service = null): string
    {
        $service ??= $item->service;
        $where = $item->tooth !== null ? ' ('.$item->tooth.($item->surfaces ? ' '.$item->surfaces : '').')' : '';

        return ($service?->name ?? 'Dental procedure').$where;
    }

    /** The newest open visit of the patient, where a finished procedure is billed. */
    private function openVisitFor(DentalTreatmentItem $item): ?Visit
    {
        return Visit::query()->where('patient_id', $item->patient_id)->latest('id')->get()
            ->first(fn (Visit $v) => $v->status->isOpen());
    }

    // ── Recall ─────────────────────────────────────────────────────────

    /** Set (or clear) the next check-up; the default is six months from today. */
    public function setRecall(Patient $patient, ?Carbon $on): Patient
    {
        if ($on !== null && $on->isPast() && ! $on->isToday()) {
            throw ValidationException::withMessages(['next_recall_on' => ['A recall is in the future.']]);
        }
        $patient->forceFill(['next_recall_on' => $on?->toDateString()])->save();

        return $patient;
    }

    public static function defaultRecall(): Carbon
    {
        return now()->addMonthsNoOverflow(self::RECALL_MONTHS)->startOfDay();
    }

    // ── Quote and reports ──────────────────────────────────────────────

    /**
     * What the patient is quoted: the planned and accepted items and the total.
     *
     * @return array{items: Collection<int, DentalTreatmentItem>, total: string}
     */
    public function quote(Patient $patient): array
    {
        $items = $this->plan($patient)->filter(fn (DentalTreatmentItem $i) => in_array($i->status, [DentalTreatmentStatus::Planned, DentalTreatmentStatus::Accepted, DentalTreatmentStatus::InProgress], true))->values();

        return ['items' => $items, 'total' => $items->reduce(fn (string $sum, DentalTreatmentItem $i) => bcadd($sum, (string) $i->price, 2), '0.00')];
    }

    /**
     * Procedures done between two dates: by dentist, by procedure and by
     * month, with revenue; and plans accepted against done.
     *
     * @return array<string,mixed>
     */
    public function report(Carbon $from, Carbon $to): array
    {
        $done = DentalTreatmentItem::query()->with(['service:id,name', 'doneBy:id,name'])
            ->where('status', DentalTreatmentStatus::Done->value)
            ->whereBetween('done_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        $group = fn (callable $key) => $done->groupBy($key)->map(fn (Collection $rows, $name) => [
            'name' => (string) $name,
            'count' => $rows->count(),
            'revenue' => $rows->reduce(fn (string $s, DentalTreatmentItem $i) => bcadd($s, (string) $i->price, 2), '0.00'),
        ])->sortByDesc('count')->values()->all();

        $planned = DentalTreatmentItem::query()->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        return [
            'by_dentist' => $group(fn (DentalTreatmentItem $i) => $i->doneBy?->name ?? 'Unknown'),
            'by_procedure' => $group(fn (DentalTreatmentItem $i) => $i->service?->name ?? 'Unknown'),
            'by_month' => $group(fn (DentalTreatmentItem $i) => $i->done_at?->format('Y-m') ?? ''),
            'planned' => (clone $planned)->count(),
            'accepted' => (clone $planned)->whereNotNull('accepted_at')->count(),
            'done' => (clone $planned)->where('status', DentalTreatmentStatus::Done->value)->count(),
            'revenue' => $done->reduce(fn (string $s, DentalTreatmentItem $i) => bcadd($s, (string) $i->price, 2), '0.00'),
        ];
    }
}
