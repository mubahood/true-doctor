<?php

namespace App\Services;

use App\Enums\AdmissionStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\LabTest;
use App\Models\Order;
use App\Models\RadiologyStudy;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use App\Support\Practice\Practice;
use App\Support\SampleCatalogue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use RuntimeException;

/**
 * Placing and moving the work on a visit — what the web's orders panel and
 * order dialog decide, in one place, so the app decides it the same way
 * (docs/orders.md).
 *
 * OrderService, LabService, RadiologyService and AdmissionService own the
 * machinery; this chooses which door each kind of work goes through, and
 * holds the guards the panel put in front of them.
 */
final class OrderDesk
{
    /** Catalogue rows are capped — a picker is for finding, not for browsing. */
    public const PICK_LIMIT = 40;

    /** Enough phrasings to recognise one, not so many that reading them is work. */
    public const TITLE_LIMIT = 6;

    public function __construct(private readonly OrderService $orders) {}

    /** Whether this person may put work on a visit, or move it along. */
    public static function canWrite(?User $user): bool
    {
        return (bool) ($user?->can('visits.create') || $user?->can('visits.manage'));
    }

    /**
     * A visit that has been completed or called off is not a place to put new
     * work: an order on a cancelled visit bills a patient for an attendance
     * the hospital decided did not happen.
     */
    public static function assertOpen(Visit $visit): void
    {
        if (! $visit->isOpen()) {
            throw new RuntimeException(
                'This visit is '.strtolower($visit->stateLabel()).' — reopen it before adding anything to it.'
            );
        }
    }

    /**
     * The kinds of work this hospital is offered, in the order they are shown.
     *
     * A kind that needs a part of the system the practice does not use is
     * left out: a dental clinic has no wards, so it is never offered an
     * admission, and place() refuses one if it is asked anyway.
     *
     * @return list<OrderType>
     */
    public static function kinds(?Practice $practice = null): array
    {
        $practice ??= Practice::current();

        return array_values(array_filter(OrderType::cases(), fn (OrderType $t) => self::offers($t, $practice)));
    }

    public static function offers(OrderType $type, ?Practice $practice = null): bool
    {
        $module = $type->module();

        return $module === null || ($practice ?? Practice::current())->uses($module);
    }

    /**
     * What the order form calls its fields. Plain statements, never questions;
     * a dental clinic's work is a treatment and its clinician a dentist.
     *
     * @return array{kind:string,title:string,assignee:string}
     */
    public static function wording(?Practice $practice = null): array
    {
        $dental = ($practice ?? Practice::current())->sector->key === 'dental_clinic';

        return [
            'kind' => $dental ? 'Treatment' : 'Type',
            'title' => 'Service',
            'assignee' => $dental ? 'Dentist assigned' : 'Clinician assigned',
        ];
    }

    /**
     * The kinds offered and the words for the form, for the app's meta.
     *
     * @return array{kinds:list<array{value:string,label:string,icon:string,takes_title:bool}>,labels:array{kind:string,title:string,assignee:string}}
     */
    public static function form(?Practice $practice = null): array
    {
        $practice ??= Practice::current();

        return [
            'kinds' => array_map(fn (OrderType $t) => [
                'value' => $t->value, 'label' => $t->label(), 'icon' => $t->icon(), 'takes_title' => $t->takesTitle(),
            ], self::kinds($practice)),
            'labels' => self::wording($practice),
        ];
    }

    /**
     * Who an order may be assigned to: the people who see patients here (every
     * doctor, and anyone marked as also seeing patients), still active.
     */
    public static function assigneeRule(?int $hospitalId = null): Exists
    {
        $hospitalId ??= app(CurrentHospital::class)->id();

        return Rule::exists('users', 'id')->where(fn ($q) => $q
            ->where('hospital_id', $hospitalId)
            ->where('is_active', true)
            ->where(fn ($qq) => $qq->where('role', 'doctor')->orWhere('sees_patients', true)));
    }

    /** Whether a person may be given clinical work at this hospital. */
    public static function isAssignable(int $userId, ?int $hospitalId): bool
    {
        return $hospitalId !== null && User::query()
            ->whereKey($userId)
            ->where('hospital_id', $hospitalId)
            ->where('is_active', true)
            ->clinicians()
            ->exists();
    }

    /** Who a new order starts assigned to: the visit's own clinician, when there is one. */
    public static function defaultAssignee(Visit $visit): ?int
    {
        $doctor = $visit->doctor_user_id;

        return $doctor !== null && self::isAssignable((int) $doctor, $visit->hospital_id) ? (int) $doctor : null;
    }

    /**
     * What placing each kind of work needs. Every id is checked inside this
     * hospital: an `exists` rule without the filter would accept, and leak the
     * existence of, another hospital's rows.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(OrderType $type): array
    {
        $hospital = app(CurrentHospital::class)->id();
        $inHospital = fn (string $table) => Rule::exists($table, 'id')->where('hospital_id', $hospital);

        return match ($type) {
            OrderType::Lab => ['test_ids' => ['required', 'array', 'min:1'], 'test_ids.*' => ['integer', $inHospital('lab_tests')], 'notes' => ['nullable', 'string', 'max:2000']],
            OrderType::Imaging => ['study_ids' => ['required', 'array', 'min:1'], 'study_ids.*' => ['integer', $inHospital('radiology_studies')], 'notes' => ['nullable', 'string', 'max:2000']],
            OrderType::Admission => [
                'title' => ['required', 'string', 'max:160'],
                'bed_id' => ['required', 'integer', $inHospital('beds')],
                'assigned_to' => ['nullable', 'integer', self::assigneeRule($hospital)],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            default => [
                'title' => ['required', 'string', 'max:160'],
                'assigned_to' => ['nullable', 'integer', self::assigneeRule($hospital)],
                'department_id' => ['nullable', 'integer', $inHospital('departments')],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
        };
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [
            'bed_id.required' => 'Choose a bed for the patient.',
            'title.required' => 'Say which service this is.',
            'assigned_to.exists' => 'Choose someone who sees patients here.',
        ];
    }

    /** The permission a kind of work asks for on top of visits.create, if any. */
    private static function needs(OrderType $type): ?string
    {
        return match ($type) {
            OrderType::Lab => 'lab.order',
            OrderType::Imaging => 'radiology.order',
            OrderType::Admission => 'ipd.manage',
            default => null,
        };
    }

    // ── Changing an order after it was placed ────────────────────────────

    /**
     * Whether this person may change this order now: they could have placed
     * this kind of work, it is still open (neither completed nor cancelled),
     * and so is its visit.
     */
    public static function canEdit(Order $order, ?User $user): bool
    {
        if ($user === null || ! self::canWrite($user) || ! $order->status->isOpen() || ! ($order->visit?->isOpen() ?? false)) {
            return false;
        }

        $need = self::needs($order->type);

        return $need === null || $user->can($need);
    }

    /**
     * What a change may carry. Every field is optional; one that is absent is
     * left as it is. Only a NEW assignee must see patients: sending back the
     * person already on the order (a lab order's technician, say) is no change.
     *
     * @return array<string, list<mixed>>
     */
    public static function editRules(?Order $order = null): array
    {
        $hospital = app(CurrentHospital::class)->id();
        $keep = $order?->assigned_to !== null ? (int) $order->assigned_to : null;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($keep, $hospital): void {
                if ($value === null || (int) $value === $keep) {
                    return;
                }
                if (! self::isAssignable((int) $value, $hospital)) {
                    $fail('Choose someone who sees patients here.');
                }
            }],
            'service_id' => ['sometimes', 'nullable', 'integer', Rule::exists('services', 'id')->where('hospital_id', $hospital)->where('is_active', true)->withoutTrashed()],
            // Plain decimals only: "1e3" passes `numeric` but not bcmath.
            'quantity' => ['sometimes', 'nullable', 'decimal:0,2', 'gt:0'],
            'unit_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'lines' => ['sometimes', 'array', 'max:50'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'decimal:0,2', 'min:0'],
        ];
    }

    /**
     * Change an order: its service, notes, assigned clinician and, until the
     * visit is invoiced, its charge. OrderService does the work and keeps the
     * bill in step; this is the door, with the guards placing has.
     *
     * @param  array<string,mixed>  $data  validated against editRules()
     *
     * @throws AuthorizationException when this person could not place this kind of work
     * @throws RuntimeException a sentence for the reader
     */
    public function edit(Order $order, array $data, User $by): Order
    {
        if (! self::canWrite($by)) {
            throw new AuthorizationException;
        }
        $need = self::needs($order->type);
        if ($need !== null && ! $by->can($need)) {
            throw new AuthorizationException;
        }

        /** @var Visit $visit */
        $visit = $order->visit()->firstOrFail();
        self::assertOpen($visit);

        return $this->orders->edit($order, $data, $by->id);
    }

    /**
     * Place one order of any kind, and say what happened.
     *
     * @param  array<string,mixed>  $data  validated against rules($type)
     *
     * @throws AuthorizationException when the kind needs a permission they lack
     * @throws RuntimeException a sentence for the reader (a bed already taken, a finished visit)
     */
    public function place(Visit $visit, OrderType $type, array $data, User $by): string
    {
        self::assertOpen($visit);

        if (! self::offers($type)) {
            throw new RuntimeException($type === OrderType::Admission
                ? 'This practice does not admit patients, so an admission cannot be placed.'
                : $type->label().' is not used at this practice, so it cannot be ordered.');
        }

        $need = self::needs($type);
        if ($need !== null && ! $by->can($need)) {
            throw new AuthorizationException;
        }

        $notes = $data['notes'] ?? null;

        switch ($type) {
            case OrderType::Lab:
                app(LabService::class)->order($visit, array_map('intval', $data['test_ids']), $notes, $by->id);

                return 'Lab tests ordered.';

            case OrderType::Imaging:
                app(RadiologyService::class)->order($visit, array_map('intval', $data['study_ids']), $notes, $by->id);

                return 'Imaging ordered.';

            case OrderType::Admission:
                // Admitting IS the order: AdmissionService takes the bed under a
                // lock, opens the admission and raises the order, in one go.
                app(AdmissionService::class)->admit(
                    $visit->patient,
                    Bed::findOrFail((int) $data['bed_id']),
                    [
                        'visit_id' => $visit->id,
                        'admitting_doctor_id' => $data['assigned_to'] ?? null,
                        'title' => $data['title'],
                        'reason' => $notes,
                    ],
                    $by->id,
                );

                return 'Patient admitted.';

            default:
                $this->orders->place($visit, $type, (string) $data['title'], [
                    'assigned_to' => $data['assigned_to'] ?? null,
                    'department_id' => $data['department_id'] ?? null,
                    'notes' => $notes,
                ], $by->id);

                return 'Order placed.';
        }
    }

    /**
     * Move an order along (never to Cancelled — that goes through cancel(),
     * which takes a reason and undoes the money and the goods).
     *
     * Finishing an inpatient stay is a discharge, not a status change: the bed
     * has to be freed and the nights billed, and AdmissionService does both.
     */
    public function move(Order $order, OrderStatus $to, User $by, ?string $note = null): string
    {
        if ($to === OrderStatus::Cancelled) {
            throw new RuntimeException('Cancelling an order asks for a reason — use Cancel.');
        }

        $stay = $order->subject;
        if ($to === OrderStatus::Completed && $stay instanceof Admission && $stay->status->isActive()) {
            if (! $by->can('ipd.manage')) {
                throw new AuthorizationException;
            }
            app(AdmissionService::class)->discharge($stay, AdmissionStatus::Discharged, $note ?: null, $by->id);

            return 'Patient discharged and the bed freed.';
        }

        $this->orders->transition($order, $to, $by->id);

        return 'Order '.strtolower($to->label()).'.';
    }

    /**
     * The tests or studies a kind of work is placed from, filtered in the
     * query — a hospital with four hundred tests must not send them all.
     *
     * @param  list<int>  $chosen  kept in the list whatever the search
     * @return array{rows:list<array{id:int,name:string,price:string}>,total:int}
     */
    public static function catalogue(?string $type, string $term = '', array $chosen = []): array
    {
        $query = match ($type) {
            OrderType::Lab->value => LabTest::query(),
            OrderType::Imaging->value => RadiologyStudy::query(),
            default => null,
        };

        if ($query === null) {
            return ['rows' => [], 'total' => 0];
        }

        $term = trim($term);
        $total = (clone $query)->where('is_active', true)->count();

        $rows = $query->select(['id', 'name', 'price'])
            ->where('is_active', true)
            ->when($term !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', '%'.$term.'%')
                ->orWhereIn('id', $chosen)))
            ->orderBy('name')
            ->limit(self::PICK_LIMIT)
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name, 'price' => (string) $row->price])
            ->values()->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * How this kind of work is usually written down: this hospital's own
     * phrasing first, topped up from the curated set. Only kinds with a free
     * title have any.
     *
     * @return list<string>
     */
    public static function titleSuggestions(?string $type): array
    {
        $curated = SampleCatalogue::orderTitles()[$type] ?? null;
        if ($curated === null) {
            return [];
        }

        $used = Order::query()
            ->where('type', $type)
            ->whereNotNull('title')
            // Titles generated when charges were carried over into orders are
            // import artefacts, not the hospital's phrasing.
            ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', '!=', Order::CARRIED_OVER))
            ->where('created_at', '>=', now()->subMonths(6))
            ->selectRaw('title, count(*) as n')
            ->groupBy('title')->orderByDesc('n')
            ->limit(self::TITLE_LIMIT)
            ->pluck('title')->map(fn ($t) => (string) $t)->all();

        $seen = array_map('mb_strtolower', $used);
        foreach ($curated as $phrase) {
            if (count($used) >= self::TITLE_LIMIT) {
                break;
            }
            if (! in_array(mb_strtolower($phrase), $seen, true)) {
                $used[] = $phrase;
                $seen[] = mb_strtolower($phrase);
            }
        }

        return array_values($used);
    }
}
