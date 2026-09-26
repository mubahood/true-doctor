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
use App\Support\SampleCatalogue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
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
     * What placing each kind of work needs.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(OrderType $type): array
    {
        return match ($type) {
            OrderType::Lab => ['test_ids' => ['required', 'array', 'min:1'], 'test_ids.*' => ['integer', Rule::exists('lab_tests', 'id')], 'notes' => ['nullable', 'string', 'max:2000']],
            OrderType::Imaging => ['study_ids' => ['required', 'array', 'min:1'], 'study_ids.*' => ['integer', Rule::exists('radiology_studies', 'id')], 'notes' => ['nullable', 'string', 'max:2000']],
            OrderType::Admission => [
                'title' => ['required', 'string', 'max:160'],
                'bed_id' => ['required', 'integer', 'exists:beds,id'],
                'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            default => [
                'title' => ['required', 'string', 'max:160'],
                'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
                'department_id' => ['nullable', 'integer', 'exists:departments,id'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
        };
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return ['bed_id.required' => 'Choose a bed for the patient.'];
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

        $need = match ($type) {
            OrderType::Lab => 'lab.order',
            OrderType::Imaging => 'radiology.order',
            OrderType::Admission => 'ipd.manage',
            default => null,
        };
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
