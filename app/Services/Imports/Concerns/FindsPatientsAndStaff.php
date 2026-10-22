<?php

namespace App\Services\Imports\Concerns;

use App\Models\Patient;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finding the registered patient and the member of staff a row names, for
 * the imports that hang on them (appointments, visits). A patient by patient
 * number or old file number, then phone, then name; staff among this
 * hospital's active accounts by name or email. Each lookup that fails says
 * why in plain words, into $problems.
 */
trait FindsPatientsAndStaff
{
    /** @param list<string> $problems */
    protected function patient(array $v, array &$problems): ?Patient
    {
        $number = trim($v['patient_no'] ?? '');
        $name = trim($v['patient_name'] ?? '');
        $phone = trim($v['phone'] ?? '');

        if ($number !== '') {
            $found = Patient::query()->where(fn (Builder $q) => $q->where('patient_no', $number)->orWhere('old_file_no', $number))->limit(2)->get();
            if ($found->count() === 1) {
                return $found->first();
            }
            $problems[] = $found->isEmpty()
                ? 'No registered patient has the number "'.$number.'". Import or register the patient first.'
                : 'Two patients have the number "'.$number.'". Use the patient number from this system.';

            return null;
        }

        $tail = PhoneNumber::tail($phone);
        if ($tail !== null) {
            $found = Patient::query()->where(fn (Builder $q) => $q->where('phone_1', 'like', "%{$tail}")->orWhere('phone_2', 'like', "%{$tail}"))->limit(5)->get();
            if ($name !== '' && $found->count() > 1) {
                $found = $found->filter(fn (Patient $p) => $this->same($p->full_name, $name))->values();
            }
            if ($found->count() === 1) {
                return $found->first();
            }
            if ($found->count() > 1) {
                $problems[] = 'More than one patient has the phone "'.$phone.'". Add the patient number.';

                return null;
            }
        }

        if ($name !== '') {
            // "Amina Nakato" or "Nakato Amina": the first word is either name.
            $words = preg_split('/\s+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $head = (string) array_shift($words);
            $rest = implode(' ', $words);
            $lastWord = (string) array_pop($words);
            $before = trim($head.' '.implode(' ', $words));
            $found = Patient::query()->where(fn (Builder $q) => $q
                ->where(fn (Builder $x) => $x->whereRaw('lower(first_name) = ?', [$head])->whereRaw('lower(last_name) = ?', [$rest]))
                ->orWhere(fn (Builder $x) => $x->whereRaw('lower(last_name) = ?', [$head])->whereRaw('lower(first_name) = ?', [$rest]))
                ->orWhere(fn (Builder $x) => $x->whereRaw('lower(first_name) = ?', [$before])->whereRaw('lower(last_name) = ?', [$lastWord])))
                ->limit(2)->get();
            if ($found->count() === 1) {
                return $found->first();
            }
            $problems[] = $found->isEmpty()
                ? 'No registered patient is called "'.$name.'". Import or register the patient first.'
                : 'More than one patient is called "'.$name.'". Add the patient number.';

            return null;
        }

        $problems[] = 'There is no patient number, phone or name to find the patient by.';

        return null;
    }

    /** @param list<string> $problems */
    protected function doctor(string $value, array &$problems): ?User
    {
        $value = trim($value);
        if ($value === '') {
            $problems[] = 'There is no doctor.';

            return null;
        }
        $plain = mb_strtolower(trim((string) preg_replace('/^(dr\.?|doctor)\s+/i', '', $value)));

        // Staff of THIS hospital only: users carry no tenant scope of their own.
        $found = User::query()->currentHospital()->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereRaw('lower(name) = ?', [$plain])
                ->orWhereRaw('lower(name) = ?', [mb_strtolower($value)])
                ->orWhereRaw('lower(email) = ?', [mb_strtolower($value)])
                ->orWhereRaw('lower(name) = ?', ['dr '.$plain])
                ->orWhereRaw('lower(name) = ?', ['dr. '.$plain]))
            ->limit(2)->get();

        if ($found->count() === 1) {
            return $found->first();
        }
        $problems[] = $found->isEmpty()
            ? 'No staff member here is called "'.$value.'". Check the spelling against Staff, or add them first.'
            : 'More than one staff member is called "'.$value.'". Use their email address instead.';

        return null;
    }
}
