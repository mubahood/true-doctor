<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search - one box that finds a patient, a visit, an invoice, a
 * stock item or a colleague.
 *
 * ── How it stays fast without FULLTEXT ──────────────────────────────────────
 * Two passes per type. First an indexed prefix match (`LIKE 'x%'`, which MySQL
 * and SQLite can answer from the hospital-leading indexes on the number, name
 * and phone columns). Only if that fills fewer than `limit` slots does a second,
 * unindexed substring pass (`LIKE '%x%'`) top it up - so the common search,
 * "type the first letters or digits", never scans.
 *
 * ── Ranking ─────────────────────────────────────────────────────────────────
 * exact number (100) > number prefix (90) > name prefix (70) > phone prefix
 * (60) > substring (40). Computed from the rows returned, so the order never
 * disagrees with what the screen shows.
 *
 * ── Safety ──────────────────────────────────────────────────────────────────
 * Every model but User carries the hospital global scope; staff are limited
 * with the same `manageableBy` the staff page uses. A type the user's
 * permissions do not allow is OMITTED from the answer - not an empty group, so
 * the response does not reveal that the module exists. The query text has LIKE
 * wildcards removed, and `limit` is capped at 10 per type.
 */
class SearchController extends Controller
{
    public const TYPES = ['patients', 'visits', 'invoices', 'stock', 'staff'];

    public const MIN_CHARS = 2;

    public const MAX_LIMIT = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:100'],
            'types' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer'],
        ]);

        $q = $this->clean((string) $data['q']);

        if (mb_strlen($q) < self::MIN_CHARS) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                'Type at least '.self::MIN_CHARS.' characters to search.',
                422,
                ['q' => ['Type at least '.self::MIN_CHARS.' characters to search.']],
            );
        }

        $wanted = $this->types((string) ($data['types'] ?? ''));
        $limit = max(1, min((int) ($data['limit'] ?? 5), self::MAX_LIMIT));
        $user = $request->user();

        $groups = [];

        foreach ($wanted as $type) {
            if (! $this->allowed($user, $type)) {
                continue;
            }

            $hits = $this->{'search'.ucfirst($type)}($user, $q, $limit);

            $groups[] = ['type' => $type, 'hits' => $hits];
        }

        return ApiResponse::success([
            'q' => $q,
            'limit' => $limit,
            'groups' => $groups,
        ]);
    }

    /** Wildcards and the escape character have no place in a typed query. */
    private function clean(string $q): string
    {
        return trim((string) preg_replace('/[%_\\\\\s]+/u', ' ', $q));
    }

    /** @return list<string> */
    private function types(string $raw): array
    {
        if (trim($raw) === '') {
            return self::TYPES;
        }

        $asked = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($raw))))));
        $unknown = array_diff($asked, self::TYPES);

        if ($unknown !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'types' => ['Unknown type: '.implode(', ', $unknown).'. Use '.implode(', ', self::TYPES).'.'],
            ]);
        }

        return array_values(array_intersect(self::TYPES, $asked));
    }

    private function allowed(User $user, string $type): bool
    {
        return match ($type) {
            'patients' => $user->can('viewAny', Patient::class),
            'visits' => $user->can('viewAny', Visit::class),
            'invoices' => $user->can('viewAny', Invoice::class),
            'stock' => $user->can('viewAny', StockItem::class),
            'staff' => $user->can('viewAny', User::class),
            default => false,
        };
    }

    // ── Types ────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function searchPatients(User $user, string $q, int $limit): array
    {
        $words = explode(' ', $q);
        $columns = ['patient_no', 'first_name', 'last_name', 'phone_1', 'phone_2'];

        $prefix = Patient::query();
        foreach ($words as $word) {
            $prefix->where(fn (Builder $w) => $this->anyLike($w, $columns, $word.'%'));
        }

        return $this->twoPass(
            $prefix,
            Patient::matchWords(Patient::query(), implode(' ', $words)),
            $limit,
            'patient_no',
            fn ($p) => $this->hit('patients', $p->uuid, $p->full_name, trim($p->patient_no.' '.($p->phone_1 ? '· '.$p->phone_1 : '')), $this->score($q, [$p->patient_no], [$p->first_name, $p->last_name, $p->full_name], [$p->phone_1, $p->phone_2]), 'patients/'.$p->uuid),
        );
    }

    /** @return list<array<string,mixed>> */
    private function searchVisits(User $user, string $q, int $limit): array
    {
        return $this->twoPass(
            Visit::query()->with('patient')->where('visit_no', 'like', $q.'%'),
            Visit::query()->with('patient')->where(fn (Builder $w) => $w
                ->where('visit_no', 'like', '%'.$q.'%')
                ->orWhereHas('patient', fn ($p) => Patient::matchWords($p, $q))),
            $limit,
            'visit_no',
            fn ($v) => $this->hit('visits', $v->uuid, (string) $v->visit_no, trim((data_get($v, 'patient.full_name') ?? '').' · '.$v->status->label(), ' ·'), $this->score($q, [$v->visit_no], [data_get($v, 'patient.full_name')], []), 'visits/'.$v->uuid),
        );
    }

    /** @return list<array<string,mixed>> */
    private function searchInvoices(User $user, string $q, int $limit): array
    {
        return $this->twoPass(
            Invoice::query()->with('patient')->where('invoice_no', 'like', $q.'%'),
            Invoice::query()->with('patient')->matching($q),
            $limit,
            'invoice_no',
            fn ($i) => $this->hit('invoices', $i->uuid, (string) $i->invoice_no, trim((data_get($i, 'patient.full_name') ?? '').' · '.$i->status->value, ' ·'), $this->score($q, [$i->invoice_no], [data_get($i, 'patient.full_name')], []), 'invoices/'.$i->uuid),
        );
    }

    /** @return list<array<string,mixed>> */
    private function searchStock(User $user, string $q, int $limit): array
    {
        return $this->twoPass(
            StockItem::query()->where(fn (Builder $w) => $this->anyLike($w, ['name', 'sku'], $q.'%')),
            StockItem::query()->where(fn (Builder $w) => $this->anyLike($w, ['name', 'sku'], '%'.$q.'%')),
            $limit,
            'name',
            fn ($s) => $this->hit('stock', $s->uuid, (string) $s->name, trim(($s->sku ? $s->sku.' · ' : '').$s->unit, ' ·'), $this->score($q, [$s->sku], [$s->name], []), 'stock-items/'.$s->uuid),
        );
    }

    /** @return list<array<string,mixed>> */
    private function searchStaff(User $user, string $q, int $limit): array
    {
        return $this->twoPass(
            User::query()->manageableBy($user)->where(fn (Builder $w) => $this->anyLike($w, ['name', 'email'], $q.'%')),
            User::query()->manageableBy($user)->where(fn (Builder $w) => $this->anyLike($w, ['name', 'email'], '%'.$q.'%')),
            $limit,
            'name',
            fn ($u) => $this->hit('staff', null, (string) $u->name, (string) $u->role_label, $this->score($q, [], [$u->name, $u->email], []), 'staff/'.$u->id) + ['id' => $u->id],
        );
    }

    // ── Machinery ────────────────────────────────────────────────────────

    /**
     * The two passes: indexed prefix first, then substring only to fill what
     * is left, skipping rows the first pass already found. Both queries are
     * built up front (building one is free); only the second one's execution
     * is conditional. Each row becomes a hit via `$toHit`; the result is
     * best score first.
     *
     * @param  Builder<covariant Model>  $prefix
     * @param  Builder<covariant Model>  $substring
     * @param  callable(mixed): array<string,mixed>  $toHit
     * @return list<array<string,mixed>>
     */
    private function twoPass(Builder $prefix, Builder $substring, int $limit, string $orderBy, callable $toHit): array
    {
        $prefix->orderBy($orderBy);
        $prefix->take($limit);
        $rows = $prefix->get();

        if ($rows->count() < $limit) {
            $substring->whereNotIn($substring->getModel()->getQualifiedKeyName(), $rows->modelKeys());
            $substring->orderBy($orderBy);
            $substring->take($limit - $rows->count());
            $rows = $rows->concat($substring->get());
        }

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = $toHit($row);
        }

        usort($hits, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $hits;
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns
     */
    private function anyLike(Builder $query, array $columns, string $pattern): void
    {
        foreach ($columns as $column) {
            $query->orWhere($column, 'like', $pattern);
        }
    }

    /**
     * @param  list<?string>  $numbers  identifiers: an exact match is the best there is
     * @param  list<?string>  $names
     * @param  list<?string>  $phones
     */
    private function score(string $q, array $numbers, array $names, array $phones): int
    {
        $q = mb_strtolower($q);
        $best = 0;

        foreach ($numbers as $value) {
            $v = mb_strtolower((string) $value);
            if ($v === '') {
                continue;
            }
            $best = max($best, match (true) {
                $v === $q => 100,
                str_starts_with($v, $q) => 90,
                str_contains($v, $q) => 40,
                default => 0,
            });
        }

        foreach ($names as $value) {
            $v = mb_strtolower((string) $value);
            $words = preg_split('/\s+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $starts = str_starts_with($v, $q) || array_filter($words, fn (string $w) => str_starts_with($w, $q)) !== [];
            $best = max($best, match (true) {
                $v === $q => 80,
                $starts => 70,
                str_contains($v, $q) => 40,
                default => 0,
            });
        }

        foreach ($phones as $value) {
            $v = mb_strtolower((string) $value);
            $best = max($best, match (true) {
                $v === '' => 0,
                str_starts_with($v, $q) => 60,
                str_contains($v, $q) => 40,
                default => 0,
            });
        }

        // A multi-word query matched by the substring pass on separate fields
        // still counts as a (weak) hit.
        return $best > 0 ? $best : 40;
    }

    /** @return array<string,mixed> */
    private function hit(string $type, ?string $uuid, string $title, string $subtitle, int $score, string $route): array
    {
        return [
            'type' => $type,
            'uuid' => $uuid,
            'title' => $title,
            'subtitle' => $subtitle,
            'score' => $score,
            'route' => $route,
        ];
    }
}
