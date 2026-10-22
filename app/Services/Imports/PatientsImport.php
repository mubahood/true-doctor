<?php

namespace App\Services\Imports;

use App\Enums\InvoiceStatus;
use App\Enums\PatientSex;
use App\Http\Requests\PatientRequest;
use App\Models\Appointment;
use App\Models\DentalChartEntry;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\PatientDocument;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\DuplicateFinder;
use App\Services\PatientService;
use App\Support\PhoneNumber;
use RuntimeException;

/**
 * Patients from the clinic's old register. Each one is registered through
 * PatientService (its own patient number, in this hospital), and a balance
 * they owed from before becomes a balance brought forward through
 * BillingService, so it is owed, shown and paid like any other bill.
 */
class PatientsImport extends ImportHandler
{
    public function __construct(
        private readonly PatientService $patients,
        private readonly BillingService $billing,
        private readonly DuplicateFinder $duplicates,
    ) {}

    public function fields(): array
    {
        return [
            new ImportField('old_file_no', 'Old file number', 'The number on their paper file or in your old system. Kept on the record, and searching for it finds them.', 'PT 1043', false,
                ['file no', 'file number', 'old file no', 'old no', 'old number', 'patient no', 'patient number', 'reg no', 'registration no', 'registration number', 'card no', 'opd no', 'opd number', 'record no', 'file', 'id', 'old id', 'ref', 'ref no']),
            new ImportField('full_name', 'Full name', 'Use this OR the two name columns. The first word becomes the first name and the rest the last name.', '', false,
                ['name', 'names', 'patient name', 'patients name', 'patient', 'client', 'client name', 'full names']),
            new ImportField('first_name', 'First name', 'Their first or given name.', 'Amina', false,
                ['firstname', 'given name', 'given names', 'fname', 'forename', 'other names', 'first']),
            new ImportField('last_name', 'Last name', 'Their surname.', 'Nakato', false,
                ['lastname', 'surname', 'family name', 'lname', 'last']),
            new ImportField('sex', 'Sex', 'Male or Female (M or F is fine).', 'Female', false, ['gender', 'm/f', 'sex m/f', 'm f']),
            new ImportField('dob', 'Date of birth', 'Day first: 25/12/1990. Leave empty and give the age if the date is not known.', '14/02/1991', false,
                ['dob', 'd.o.b', 'd o b', 'birth date', 'birthdate', 'date of birth dd/mm/yyyy', 'born', 'birthday']),
            new ImportField('age', 'Age', 'In years, used only when there is no date of birth ("6 months" also works).', '', false,
                ['age years', 'age yrs', 'age in years', 'years', 'yrs']),
            new ImportField('phone', 'Phone', 'Any way it is written: 0772 123456, +256772123456.', '0772 123456', false,
                ['tel', 'telephone', 'phone no', 'phone number', 'phone 1', 'contact', 'contacts', 'contact no', 'contact number', 'mobile', 'mobile no', 'mobile number', 'cell', 'tel no', 'telephone number', 'mob']),
            new ImportField('phone_2', 'Other phone', 'A second number, if they have one.', '', false,
                ['phone 2', 'phone2', 'alternative phone', 'alt phone', 'second phone', 'other contact', 'other number']),
            new ImportField('email', 'Email', 'Their email address, if they have one.', '', false, ['e-mail', 'email address', 'mail']),
            new ImportField('address', 'Address', 'Where they live.', 'Kireka, Wakiso', false,
                ['residence', 'location', 'village', 'home', 'physical address', 'home address', 'place', 'area']),
            new ImportField('next_of_kin', 'Next of kin', 'The person to call about them.', 'Joseph Okello (husband)', false,
                ['nok', 'next of kin name', 'emergency contact', 'emergency contact name', 'kin', 'guardian', 'contact person', 'nok name']),
            new ImportField('next_of_kin_phone', 'Next of kin phone', 'That person\'s phone.', '0701 987654', false,
                ['nok phone', 'nok contact', 'nok tel', 'next of kin contact', 'next of kin tel', 'next of kin phone no', 'emergency phone', 'emergency contact phone', 'guardian phone', 'kin phone']),
            new ImportField('allergies', 'Allergies', 'Separate several with commas. Leave empty, or write None, if there are none.', 'Penicillin', false,
                ['allergy', 'allergic to', 'known allergies', 'drug allergies']),
            new ImportField('conditions', 'Conditions', 'Long-term conditions, separated with commas.', 'Hypertension', false,
                ['condition', 'chronic conditions', 'chronic condition', 'medical conditions', 'medical history', 'illnesses', 'chronic illness', 'chronic diseases']),
            new ImportField('blood_type', 'Blood group', 'A+, A-, B+, B-, AB+, AB-, O+ or O-.', '', false, ['blood type', 'blood', 'bg', 'blood grp']),
            new ImportField('opening_balance', 'Balance owed', 'What they owed you before, as a number. It becomes a balance brought forward on their record. Leave empty if nothing.', '25000', false,
                ['balance', 'opening balance', 'owes', 'owed', 'amount owed', 'debt', 'arrears', 'outstanding', 'balance due', 'bal', 'unpaid']),
            new ImportField('notes', 'Notes', 'Anything else worth keeping on the record.', '', false, ['remarks', 'comments', 'comment', 'note']),
        ];
    }

    public function decisions(): array
    {
        return ['skip', 'add', 'update'];
    }

    public function requiredColumns(): array
    {
        // A name, in one column or two.
        return [['full_name', 'first_name']];
    }

    public function sheetKey(array $v): ?string
    {
        [$first, $last] = $this->names($v);
        if ($first === '' || $last === '') {
            return null;
        }
        $dob = $this->date($v['dob'] ?? '');
        $tail = PhoneNumber::tail($v['phone'] ?? null);
        if (! is_string($dob) && $tail === null) {
            return null;
        }

        return mb_strtolower($first.'|'.$last).'|'.(is_string($dob) ? $dob : '').'|'.($tail ?? '');
    }

    public function check(array $v): array
    {
        $problems = [];
        [$first, $last] = $this->names($v);

        if ($first === '' && $last === '') {
            $problems[] = 'There is no name.';
        } elseif ($first === '' || $last === '') {
            $problems[] = 'Only one name is given. Add the other name.';
        } elseif (mb_strlen($first) > 100 || mb_strlen($last) > 100) {
            $problems[] = 'The name is longer than 100 letters.';
        }

        if (($v['sex'] ?? '') !== '' && $this->sex($v['sex']) === null) {
            $problems[] = 'Sex "'.$v['sex'].'" is not one we know. Write Male or Female.';
        }

        $dob = $this->date($v['dob'] ?? '');
        if ($dob === false) {
            $problems[] = 'Date of birth "'.$v['dob'].'" is not a date we can read. Write it like 25/12/1990.';
        } elseif (is_string($dob) && $dob > today()->toDateString()) {
            $problems[] = 'Date of birth '.$dob.' is in the future.';
        } elseif (is_string($dob) && $dob < today()->subYears(130)->toDateString()) {
            $problems[] = 'Date of birth '.$dob.' is more than 130 years ago.';
        } elseif ($dob === null && ($v['age'] ?? '') !== '' && $this->ageToDob($v['age']) === null) {
            $problems[] = 'Age "'.$v['age'].'" is not an age we can read. Write it in years, like 34.';
        }

        foreach (['phone' => 'Phone', 'phone_2' => 'Other phone', 'next_of_kin_phone' => 'Next of kin phone'] as $key => $label) {
            $phone = $v[$key] ?? '';
            if ($phone !== '' && (PhoneNumber::tail($phone) === null || mb_strlen($phone) > 32)) {
                $problems[] = $label.' "'.$phone.'" is not a phone number we can read.';
            }
        }

        if (($v['email'] ?? '') !== '' && filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'Email "'.$v['email'].'" is not an email address.';
        }

        if (($v['blood_type'] ?? '') !== '' && $this->bloodType($v['blood_type']) === null) {
            $problems[] = 'Blood group "'.$v['blood_type'].'" is not one we know. Write it like O+ or AB-.';
        }

        $balance = $this->money($v['opening_balance'] ?? '');
        if ($balance === false) {
            $problems[] = 'Balance owed "'.$v['opening_balance'].'" is not an amount.';
        } elseif (is_string($balance) && bccomp($balance, '0', 2) < 0) {
            $problems[] = 'Balance owed is below zero. Leave it empty when they owe nothing.';
        }

        if (mb_strlen($v['old_file_no'] ?? '') > 50) {
            $problems[] = 'The old file number is longer than 50 characters.';
        }

        return ['problems' => $problems, 'match' => $problems === [] ? $this->match($v, $first, $last, is_string($dob) ? $dob : null) : null];
    }

    /** @return array{id:int,reason:string,label:string}|null */
    private function match(array $v, string $first, string $last, ?string $dob): ?array
    {
        $old = trim($v['old_file_no'] ?? '');
        if ($old !== '') {
            $hit = Patient::query()->where('old_file_no', $old)->orderBy('id')->first();
            if ($hit !== null) {
                return ['id' => $hit->id, 'reason' => 'Same old file number', 'label' => $hit->full_name.' ('.$hit->patient_no.')'];
            }
        }

        $found = $this->duplicates->registered(['first' => $first, 'last' => $last, 'dob' => $dob, 'phone' => $v['phone'] ?? null]);

        // A row with nothing else to tell two people apart (no phone, no date
        // of birth, no old file number) is matched on the exact name, so the
        // same sheet uploaded twice does not register everybody twice.
        if ($found === null && $old === '' && $dob === null && trim($v['phone'] ?? '') === '') {
            $hit = Patient::query()->whereRaw('lower(first_name) = ?', [mb_strtolower($first)])->whereRaw('lower(last_name) = ?', [mb_strtolower($last)])->orderBy('id')->first();
            $found = $hit === null ? null : ['patient' => $hit, 'reason' => 'Same name'];
        }

        return $found === null ? null : [
            'id' => $found['patient']->id,
            'reason' => $found['reason'],
            'label' => $found['patient']->full_name.' ('.$found['patient']->patient_no.')',
        ];
    }

    public function save(array $v, string $decision, ?int $matchId, ImportBatch $batch, ?int $by): array
    {
        $data = $this->data($v);
        $balance = $this->money($v['opening_balance'] ?? '');
        $balance = is_string($balance) && bccomp($balance, '0', 2) > 0 ? $balance : null;

        if ($decision === 'update' && $matchId !== null) {
            // Locked, so two imports updating the same patient cannot both
            // add a balance brought forward.
            $patient = Patient::query()->whereKey($matchId)->lockForUpdate()->first() ?? throw new RuntimeException('The registered patient it matched is no longer on the register.');

            // Fill in what the sheet gives; never blank out what is there.
            // Who the patient IS is never changed by a sheet: a phone shared
            // by a family can match the wrong one, and an age is only an
            // estimate. The name stays; sex, date of birth and the old file
            // number are filled in only where the record has none. Allergies
            // and conditions are added to, not replaced.
            $changes = array_filter($data, fn ($value) => $value !== null && $value !== '' && $value !== []);
            unset($changes['allergies'], $changes['chronic_conditions'], $changes['first_name'], $changes['last_name']);
            foreach (['sex', 'dob', 'old_file_no'] as $identity) {
                if (filled($patient->getRawOriginal($identity))) {
                    unset($changes[$identity]);
                }
            }
            if (! isset($changes['dob'])) {
                unset($changes['dob_estimated']);
            }
            foreach (['allergies', 'chronic_conditions'] as $list) {
                if ($data[$list] !== []) {
                    $changes[$list] = array_values(array_unique(array_merge($patient->{$list} ?? [], $data[$list])));
                }
            }
            $this->patients->update($patient, $changes);

            $extra = [];
            $message = null;
            if ($balance !== null) {
                $hasOne = Invoice::query()->where('patient_id', $patient->id)->where('opening_balance', true)
                    ->where('status', '!=', InvoiceStatus::Void->value)->exists();
                if ($hasOne) {
                    $message = 'Already has a balance brought forward; the balance owed was not added again.';
                } else {
                    $extra['invoice_id'] = $this->billing->openingBalance($patient, $balance, $by)->id;
                }
            }

            return ['status' => ImportRow::UPDATED, 'type' => Patient::class, 'id' => $patient->id, 'extra' => $extra] + ($message ? ['message' => $message] : []);
        }

        $data['allergies'] = $data['allergies'] === [] ? null : $data['allergies'];
        $data['chronic_conditions'] = $data['chronic_conditions'] === [] ? null : $data['chronic_conditions'];
        $patient = $this->patients->register($data, $by);
        $extra = [];
        if ($balance !== null) {
            $extra['invoice_id'] = $this->billing->openingBalance($patient, $balance, $by)->id;
        }

        return ['status' => ImportRow::ADDED, 'type' => Patient::class, 'id' => $patient->id, 'extra' => $extra, 'message' => $patient->patient_no];
    }

    public function undoBlocker(ImportRow $row): ?string
    {
        $patient = Patient::query()->find($row->record_id);
        if ($patient === null) {
            return null; // archived already: nothing left to take back
        }

        $openingId = (int) ($row->extra['invoice_id'] ?? 0);
        // The visit that only carries the balance brought forward is the import's own.
        $openingVisit = (int) (Invoice::query()->whereKey($openingId)->value('visit_id') ?? 0);

        return match (true) {
            Visit::query()->where('patient_id', $patient->id)->whereKeyNot($openingVisit)->exists() => 'Has had a visit since.',
            Appointment::query()->where('patient_id', $patient->id)->exists() => 'Has an appointment.',
            Invoice::query()->where('patient_id', $patient->id)->whereKeyNot($openingId)->exists() => 'Has been billed since.',
            Invoice::query()->whereKey($openingId)->where(fn ($q) => $q->where('amount_paid', '>', 0)->orWhere('credited', '>', 0))->exists() => 'Has paid towards the balance brought forward.',
            PatientCard::query()->where('patient_id', $patient->id)->exists() => 'Has been given a card.',
            PatientDocument::query()->where('patient_id', $patient->id)->exists() => 'Has documents on the record.',
            DentalChartEntry::query()->where('patient_id', $patient->id)->exists() => 'Has dental chart entries.',
            $row->updated_at !== null && $patient->updated_at !== null && $patient->updated_at->gt($row->updated_at) => 'The record was changed since.',
            default => null,
        };
    }

    public function undoUpdate(ImportRow $row, ?int $by): ?string
    {
        $invoice = Invoice::query()->find($row->extra['invoice_id'] ?? 0);
        if ($invoice === null || $invoice->status === InvoiceStatus::Void) {
            return null;
        }
        if (bccomp((string) $invoice->amount_paid, '0', 2) !== 0 || bccomp((string) $invoice->credited, '0', 2) !== 0) {
            return 'Has paid towards the balance brought forward.';
        }

        $this->billing->voidOpeningBalance($invoice);

        return null;
    }

    public function undo(ImportRow $row, ?int $by): void
    {
        $patient = Patient::query()->find($row->record_id);
        if ($patient === null) {
            return;
        }

        $openingId = $row->extra['invoice_id'] ?? null;
        if ($openingId !== null && ($invoice = Invoice::query()->find($openingId)) !== null) {
            $this->billing->voidOpeningBalance($invoice);
        }

        $this->patients->archive($patient);
    }

    // ── Reading a row ──────────────────────────────────────────────────

    /** @return array{0:string,1:string} first and last name */
    private function names(array $v): array
    {
        $first = trim($v['first_name'] ?? '');
        $last = trim($v['last_name'] ?? '');
        if ($first !== '' || $last !== '') {
            return [$first, $last];
        }

        $words = preg_split('/\s+/', trim($v['full_name'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return ['', ''];
        }

        return [array_shift($words), implode(' ', $words)];
    }

    private function sex(string $value): ?PatientSex
    {
        return match (strtolower(trim($value))) {
            'm', 'male', 'man', 'boy', 'mr' => PatientSex::Male,
            'f', 'female', 'woman', 'girl', 'mrs', 'ms', 'miss' => PatientSex::Female,
            'other' => PatientSex::Other,
            default => null,
        };
    }

    private function bloodType(string $value): ?string
    {
        // "O+", "o pos", "A+ve", "AB Rh negative".
        $v = strtoupper((string) preg_replace('/\s+/', '', $value));
        $v = str_replace('RH', '', $v);
        $v = (string) preg_replace('/([+-])VE$/', '$1', $v);
        $v = (string) preg_replace('/(POSITIVE|POS)$/', '+', $v);
        $v = (string) preg_replace('/(NEGATIVE|NEG)$/', '-', $v);

        return in_array($v, PatientRequest::BLOOD_TYPES, true) ? $v : null;
    }

    /** "34", "34 yrs", "6 months", "2 weeks" as a date of birth that far back. */
    private function ageToDob(string $value): ?string
    {
        if (preg_match('/^(\d{1,3}(?:\.\d+)?)\s*(y|yr|yrs|year|years|m|mo|mos|mth|mths|month|months|w|wk|wks|week|weeks)?\.?$/i', trim($value), $m) !== 1) {
            return null;
        }
        $n = (float) $m[1];
        $unit = strtolower($m[2] ?? 'y');

        $date = match (true) {
            str_starts_with($unit, 'w') => today()->subWeeks((int) $n),
            str_starts_with($unit, 'm') => today()->subMonths((int) $n),
            default => today()->subMonths((int) round($n * 12)),
        };

        return $n > 130 ? null : $date->toDateString();
    }

    /** @return array<string,mixed> the patient's fields as PatientService takes them */
    private function data(array $v): array
    {
        [$first, $last] = $this->names($v);
        $given = $this->date($v['dob'] ?? '');
        $dob = is_string($given) ? $given : (($v['age'] ?? '') !== '' ? $this->ageToDob($v['age']) : null);
        $blood = ($v['blood_type'] ?? '') !== '' ? $this->bloodType($v['blood_type']) : null;

        return [
            'first_name' => mb_substr($first, 0, 100),
            'last_name' => mb_substr($last, 0, 100),
            'old_file_no' => ($v['old_file_no'] ?? '') !== '' ? $v['old_file_no'] : null,
            'sex' => ($v['sex'] ?? '') !== '' ? $this->sex($v['sex'])?->value : null,
            'dob' => $dob,
            // Worked out from the age column: an estimate, not a known birthday.
            'dob_estimated' => $dob !== null && ! is_string($given),
            'phone_1' => ($v['phone'] ?? '') !== '' ? $v['phone'] : null,
            'phone_2' => ($v['phone_2'] ?? '') !== '' ? $v['phone_2'] : null,
            'email' => ($v['email'] ?? '') !== '' ? mb_substr($v['email'], 0, 150) : null,
            'address' => ($v['address'] ?? '') !== '' ? mb_substr($v['address'], 0, 191) : null,
            'emergency_contact_name' => ($v['next_of_kin'] ?? '') !== '' ? mb_substr($v['next_of_kin'], 0, 150) : null,
            'emergency_contact_phone' => ($v['next_of_kin_phone'] ?? '') !== '' ? $v['next_of_kin_phone'] : null,
            'allergies' => $this->list($v['allergies'] ?? ''),
            'chronic_conditions' => $this->list($v['conditions'] ?? ''),
            'blood_type' => $blood,
            'notes' => ($v['notes'] ?? '') !== '' ? mb_substr($v['notes'], 0, 2000) : null,
        ];
    }
}
