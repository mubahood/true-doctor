<?php

namespace App\Services;

use App\Models\DiagnosisCode;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Coded diagnoses (roadmap 04 §1): the picker's search, the record on a visit
 * (append-only), the "not coded yet" worklist and the suggestions for it.
 * The HMIS reports count the primary diagnosis in force on each visit.
 */
class DiagnosisService
{
    public const CERTAINTY = ['confirmed', 'suspected'];

    /**
     * The best matches for what was typed: a code typed as a code first, then
     * this practice's own most-used diagnoses, then titles that start with the
     * words, then the rest. Every word must appear (in the code, the title or
     * a synonym, in English, Luganda or Swahili).
     *
     * @return Collection<int, DiagnosisCode>
     */
    public function search(string $typed, ?int $hospitalId = null, int $limit = 5): Collection
    {
        $q = mb_strtolower(trim($typed));
        $words = array_values(array_filter(preg_split('/\s+/', $q) ?: [], fn ($w) => mb_strlen($w) >= 2));
        if ($words === []) {
            return $hospitalId === null ? new Collection : $this->mostUsed($hospitalId, $limit);
        }

        $found = DiagnosisCode::query()->where('is_active', true)
            ->where(function (Builder $b) use ($words) {
                foreach ($words as $w) {
                    $b->where('search', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $w).'%');
                }
            })
            ->limit(80)->get();

        $used = $hospitalId === null ? [] : $this->usage($hospitalId);
        $upper = strtoupper($q);

        return $found->sortByDesc(function (DiagnosisCode $d) use ($upper, $q, $used) {
            $score = 0;
            if ($d->code === $upper || str_starts_with($d->code, $upper)) {
                $score += 1000;
            }
            $score += min(500, ($used[$d->code] ?? 0) * 10);
            if (str_starts_with(mb_strtolower($d->title), $q)) {
                $score += 100;
            }
            if (collect((array) $d->synonyms)->contains(fn ($s) => mb_strtolower((string) $s) === $q)) {
                $score += 80;
            }

            return $score - mb_strlen($d->title) / 100;
        })->take($limit)->values();
    }

    /**
     * Code a visit. Choosing a new primary makes the old primary secondary
     * (a correction row), so a visit never has two.
     */
    public function add(Visit $visit, string $code, bool $primary, string $certainty = 'confirmed', ?string $note = null, ?User $by = null, ?string $uuid = null): VisitDiagnosis
    {
        $entry = DiagnosisCode::query()->where('code', strtoupper(trim($code)))->where('is_active', true)->first();
        if ($entry === null) {
            throw ValidationException::withMessages(['code' => 'That is not a code in the diagnosis list.']);
        }
        if (! in_array($certainty, self::CERTAINTY, true)) {
            throw ValidationException::withMessages(['certainty' => 'Confirmed or suspected.']);
        }

        return DB::transaction(function () use ($visit, $entry, $primary, $certainty, $note, $by, $uuid) {
            Visit::query()->whereKey($visit->id)->lockForUpdate()->first();

            if ($uuid !== null && ($existing = VisitDiagnosis::query()->where('uuid', $uuid)->first()) !== null) {
                return $existing; // the same entry sent again (offline replay)
            }

            $current = $this->current($visit);
            if ($current->contains(fn (VisitDiagnosis $d) => $d->code === $entry->code)) {
                throw ValidationException::withMessages(['code' => 'This diagnosis is already on the visit.']);
            }
            $primary = $primary || $current->isEmpty();
            if ($primary) {
                foreach ($current->where('is_primary', true) as $old) {
                    $this->append($old, ['is_primary' => false], $by);
                }
            }

            return VisitDiagnosis::query()->create([
                'uuid' => $uuid ?? (string) Str::uuid(),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'system' => $entry->system,
                'code' => $entry->code,
                'title' => $entry->title,
                'is_primary' => $primary,
                'certainty' => $certainty,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'recorded_by' => $by?->id,
                'recorded_at' => Carbon::now(),
            ]);
        });
    }

    /** Change certainty or make it the primary: a new row that replaces this one. */
    public function change(VisitDiagnosis $diagnosis, ?bool $primary = null, ?string $certainty = null, ?User $by = null): VisitDiagnosis
    {
        return DB::transaction(function () use ($diagnosis, $primary, $certainty, $by) {
            $visit = Visit::query()->whereKey($diagnosis->visit_id)->lockForUpdate()->firstOrFail();
            if ($primary === true) {
                foreach ($this->current($visit)->where('is_primary', true)->where('id', '!=', $diagnosis->id) as $old) {
                    $this->append($old, ['is_primary' => false], $by);
                }
            }

            return $this->append($diagnosis, array_filter([
                'is_primary' => $primary,
                'certainty' => $certainty !== null && in_array($certainty, self::CERTAINTY, true) ? $certainty : null,
            ], fn ($v) => $v !== null), $by);
        });
    }

    /** Take it off the visit: a removal row. The first remaining becomes primary. */
    public function remove(VisitDiagnosis $diagnosis, ?User $by = null): void
    {
        DB::transaction(function () use ($diagnosis, $by) {
            $visit = Visit::query()->whereKey($diagnosis->visit_id)->lockForUpdate()->firstOrFail();
            $wasPrimary = $diagnosis->is_primary;
            $this->append($diagnosis, ['removed' => true, 'is_primary' => false], $by);
            if ($wasPrimary && ($next = $this->current($visit)->first()) !== null) {
                $this->append($next, ['is_primary' => true], $by);
            }
        });
    }

    /** @return Collection<int, VisitDiagnosis> in force: not replaced, not removed; primary first */
    public function current(Visit $visit): Collection
    {
        return VisitDiagnosis::query()->where('visit_id', $visit->id)
            ->where('removed', false)
            ->whereNotIn('id', VisitDiagnosis::query()->where('visit_id', $visit->id)->whereNotNull('replaces_id')->select('replaces_id'))
            ->orderByDesc('is_primary')->orderBy('id')
            ->get();
    }

    /**
     * Visits with a written diagnosis and no code in force, newest first: the
     * records officer's worklist.
     *
     * @return Builder<Visit>
     */
    public function uncoded(): Builder
    {
        $coded = VisitDiagnosis::query()->where('removed', false)
            ->whereNotIn('id', VisitDiagnosis::query()->whereNotNull('replaces_id')->select('replaces_id'))
            ->select('visit_id');

        return Visit::query()->whereNotNull('diagnosis')->where('diagnosis', '!=', '')
            ->whereNotIn('id', $coded)
            ->latest('id');
    }

    /**
     * Codes that may fit a written diagnosis. Only offered: a person chooses.
     *
     * @return Collection<int, DiagnosisCode>
     */
    public function suggest(string $written, ?int $hospitalId = null): Collection
    {
        // Phrase by phrase ("Uncomplicated malaria, confirmed by RDT" → "uncomplicated malaria"),
        // then the longest run of its words that matches ("hypertension, adequately
        // controlled" → "hypertension"), until something matches.
        $phrases = preg_split('/\s*(?:[,.;:\/()]|\band\b|\bwith\b)\s*/iu', mb_strtolower($written)) ?: [];
        // Every word of the whole text, to choose between codes that matched the
        // same words ("malaria ... confirmed by RDT" is B50.9, not B54).
        $all = array_filter(preg_split('/[^\pL\pN]+/u', mb_strtolower($written)) ?: [], fn ($w) => mb_strlen($w) >= 4);
        foreach ($phrases as $phrase) {
            $clean = (string) preg_replace('/[^\pL\pN\s-]/u', ' ', $phrase);
            $words = array_slice(array_values(array_filter(preg_split('/\s+/', trim($clean)) ?: [], fn ($w) => mb_strlen($w) >= 4)), 0, 4);
            for ($len = count($words); $len >= 1; $len--) {
                for ($start = 0; $start + $len <= count($words); $start++) {
                    $hits = $this->search(implode(' ', array_slice($words, $start, $len)), $hospitalId, 8);
                    if ($hits->isNotEmpty()) {
                        return $hits->sortByDesc(fn (DiagnosisCode $d) => count(array_filter($all, fn ($w) => str_contains($d->search, $w))))
                            ->take(3)->values();
                    }
                }
            }
        }

        return new Collection;
    }

    /** @return array<string,int> code => times used here in the last six months */
    private function usage(int $hospitalId): array
    {
        return VisitDiagnosis::query()->withoutGlobalScopes()
            ->where('hospital_id', $hospitalId)->where('removed', false)
            ->where('recorded_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw('code, count(*) as n')->groupBy('code')
            ->pluck('n', 'code')->map(fn ($n) => (int) $n)->all();
    }

    /** @return Collection<int, DiagnosisCode> */
    private function mostUsed(int $hospitalId, int $limit): Collection
    {
        $codes = array_slice(array_keys(collect($this->usage($hospitalId))->sortDesc()->all()), 0, $limit);

        return DiagnosisCode::query()->whereIn('code', $codes)->get()
            ->sortBy(fn (DiagnosisCode $d) => array_search($d->code, $codes, true))->values();
    }

    /** @param array<string,mixed> $changes */
    private function append(VisitDiagnosis $from, array $changes, ?User $by): VisitDiagnosis
    {
        return VisitDiagnosis::query()->create(array_merge(
            $from->only(['visit_id', 'patient_id', 'system', 'code', 'title', 'is_primary', 'certainty', 'note', 'removed']),
            $changes,
            ['uuid' => (string) Str::uuid(), 'replaces_id' => $from->id, 'recorded_by' => $by?->id, 'recorded_at' => Carbon::now()],
        ));
    }
}
