<?php

namespace App\Services;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Is this person already registered?" - up to LIMIT candidates, strongest
 * reason first: the same phone, the same name and date of birth, a similar name.
 * Cheap on purpose (indexed-ish LIKE on a handful of columns); tenant-scoped
 * by the Patient global scope. A hint for the desk, never a block.
 */
class DuplicateFinder
{
    public const LIMIT = 5;

    private const SAME_PHONE = 'same_phone';

    private const SAME_NAME_DOB = 'same_name_dob';

    private const SIMILAR_NAME = 'similar_name';

    private const LABELS = [
        self::SAME_PHONE => 'Same phone',
        self::SAME_NAME_DOB => 'Same name and date of birth',
        self::SIMILAR_NAME => 'Similar name',
    ];

    /**
     * @param  array{first?:?string,last?:?string,dob?:?string,phone?:?string}  $in
     * @return list<array<string,mixed>>
     */
    public function find(array $in): array
    {
        $first = $this->clean($in['first'] ?? null);
        $last = $this->clean($in['last'] ?? null);
        $dob = $in['dob'] ?? null;
        $digits = substr(preg_replace('/\D+/', '', (string) ($in['phone'] ?? '')) ?? '', -9);

        $found = [];
        if (strlen($digits) >= 7) {
            $found = $this->take($found, Patient::query()->where(fn (Builder $q) => $q
                ->whereRaw($this->stripped('phone_1').' like ?', ["%{$digits}"])
                ->orWhereRaw($this->stripped('phone_2').' like ?', ["%{$digits}"])), self::SAME_PHONE);
        }

        if ($first !== '' && $last !== '' && filled($dob)) {
            $found = $this->take($found, Patient::query()->whereRaw('lower(first_name) = ?', [$first])->whereRaw('lower(last_name) = ?', [$last])
                ->whereDate('dob', $dob), self::SAME_NAME_DOB);
        }

        if ($first !== '' && $last !== '') {
            $f = $this->like(mb_substr($first, 0, 3));
            $l = $this->like(mb_substr($last, 0, 3));
            // Same names with a different spelling at the end, or first and last swapped.
            $found = $this->take($found, Patient::query()->where(fn (Builder $q) => $q
                ->where(fn (Builder $x) => $x->whereRaw('lower(first_name) like ?', ["{$f}%"])->whereRaw('lower(last_name) like ?', ["{$l}%"]))
                ->orWhere(fn (Builder $x) => $x->whereRaw('lower(first_name) like ?', ["{$l}%"])->whereRaw('lower(last_name) like ?', ["{$f}%"]))), self::SIMILAR_NAME);
        } elseif ($last !== '' && filled($dob)) {
            $found = $this->take($found, Patient::query()->whereRaw('lower(last_name) = ?', [$last])->whereDate('dob', $dob), self::SIMILAR_NAME);
        }

        // Strongest reason first, then the order found.
        $order = array_flip([self::SAME_PHONE, self::SAME_NAME_DOB, self::SIMILAR_NAME]);
        uasort($found, fn ($a, $b) => $order[$a[1]] <=> $order[$b[1]]);

        return array_values(array_map(fn ($pair) => [
            'uuid' => $pair[0]->uuid,
            'name' => $pair[0]->full_name,
            'patient_no' => $pair[0]->patient_no,
            'dob' => $pair[0]->dob?->toDateString(),
            'sex' => $pair[0]->sex?->value,
            'phone' => $pair[0]->phone_1,
            'reason' => self::LABELS[$pair[1]],
            'reason_code' => $pair[1],
        ], array_slice($found, 0, self::LIMIT, true)));
    }

    /**
     * The registered patient a row of an imported sheet most likely already
     * is, by the two strong reasons only (Settings, Import data): the same
     * phone (its last nine digits, PhoneNumber::tail), or the same name and
     * date of birth. A similar name alone is too weak to stop a row for.
     *
     * @param  array{first?:?string,last?:?string,dob?:?string,phone?:?string}  $in
     * @return array{patient:Patient,reason:string}|null
     */
    public function registered(array $in): ?array
    {
        $first = $this->clean($in['first'] ?? null);
        $last = $this->clean($in['last'] ?? null);
        $dob = $in['dob'] ?? null;
        $tail = \App\Support\PhoneNumber::tail($in['phone'] ?? null);

        if ($tail !== null) {
            $hit = Patient::query()->where(fn (Builder $q) => $q
                ->whereRaw($this->stripped('phone_1').' like ?', ["%{$tail}"])
                ->orWhereRaw($this->stripped('phone_2').' like ?', ["%{$tail}"]))->orderBy('id')->first();
            if ($hit !== null) {
                return ['patient' => $hit, 'reason' => self::LABELS[self::SAME_PHONE]];
            }
        }

        if ($first !== '' && $last !== '' && filled($dob)) {
            $hit = Patient::query()->whereRaw('lower(first_name) = ?', [$first])->whereRaw('lower(last_name) = ?', [$last])
                ->whereDate('dob', $dob)->orderBy('id')->first();
            if ($hit !== null) {
                return ['patient' => $hit, 'reason' => self::LABELS[self::SAME_NAME_DOB]];
            }
        }

        return null;
    }

    /**
     * @param  array<int,array{0:Patient,1:string}>  $found
     * @param  Builder<Patient>  $q
     * @return array<int,array{0:Patient,1:string}>
     */
    private function take(array $found, Builder $q, string $reason): array
    {
        foreach ($q->limit(self::LIMIT)->get() as $p) {
            $found[$p->id] ??= [$p, $reason];
        }

        return $found;
    }

    private function clean(?string $v): string
    {
        return mb_strtolower(trim((string) $v));
    }

    private function like(string $v): string
    {
        return addcslashes($v, '%_\\');
    }

    /** A phone column with spaces, dashes, plus signs and brackets removed (SQLite and MySQL alike). */
    private function stripped(string $column): string
    {
        return "replace(replace(replace(replace(replace({$column}, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
    }
}
