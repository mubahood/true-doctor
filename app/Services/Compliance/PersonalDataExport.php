<?php

namespace App\Services\Compliance;

use App\Models\Patient;
use App\Models\User;
use App\Support\Compliance\TenantTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything this clinic holds about one patient, for a request to see it
 * (the Act, section 24).
 *
 * Found rather than listed: every table with a `patient_id`, and every table
 * that hangs off one of those by `<table>_id` (a lab order's results, an
 * invoice's lines, an admission's nursing notes). Internal references are
 * dropped; the staff who recorded something are named, because "who saw me"
 * is part of the answer.
 */
class PersonalDataExport
{
    /** Tables that are bookkeeping about the record, not the record. */
    private const SKIP = ['activity_log', 'attachments', 'data_requests', 'patient_dependents'];

    /** Columns that mean nothing to the patient. */
    private const HIDE = ['id', 'uuid', 'hospital_id', 'patient_id', 'deleted_at', 'updated_at', 'origin_operation_id', 'origin_device_id', 'file_path', 'photo_path', 'photo'];

    /** @var array<int,string> */
    private array $names = [];

    public function __construct(private readonly TenantTables $tables) {}

    /**
     * @return list<array{table:string,label:string,rows:list<array<string,string>>}>
     */
    public function sections(Patient $patient): array
    {
        $hospitalId = (int) $patient->hospital_id;
        $patientRow = (array) DB::table('patients')->where('hospital_id', $hospitalId)->where('id', $patient->id)->first();
        $sections = [['table' => 'patients', 'label' => 'Your details', 'rows' => [$this->present('patients', $patientRow)]]];

        $parents = [];
        foreach ($this->tables->withColumn('patient_id') as $table) {
            if (in_array($table, self::SKIP, true) || $table === 'patients') {
                continue;
            }
            $rows = DB::table($table)->where('hospital_id', $hospitalId)->where('patient_id', $patient->id)
                ->orderBy($this->tables->has($table, 'id') ? 'id' : $this->tables->columns($table)[0]['name'])
                ->get()->map(fn ($r) => (array) $r)->all();
            if ($rows === []) {
                continue;
            }
            $parents[$table] = array_values(array_filter(array_map(fn (array $r) => $r['id'] ?? null, $rows)));
            $sections[] = $this->section($table, $rows);
        }

        // One step further: the rows that belong to the rows above.
        foreach ($this->tables->withColumn('hospital_id') as $table) {
            if (in_array($table, self::SKIP, true) || isset($parents[$table]) || $table === 'patients' || $this->tables->has($table, 'patient_id')) {
                continue;
            }
            foreach ($parents as $parent => $ids) {
                $key = Str::singular($parent).'_id';
                if ($ids === [] || ! $this->tables->has($table, $key)) {
                    continue;
                }
                $rows = DB::table($table)->where('hospital_id', $hospitalId)->whereIn($key, $ids)
                    ->orderBy($this->tables->has($table, 'id') ? 'id' : $key)
                    ->get()->map(fn ($r) => (array) $r)->all();
                if ($rows !== []) {
                    $sections[] = $this->section($table, $rows);
                }
            }
        }

        return $sections;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{table:string,label:string,rows:list<array<string,string>>}
     */
    private function section(string $table, array $rows): array
    {
        return [
            'table' => $table,
            'label' => Str::headline($table),
            'rows' => array_map(fn (array $r) => $this->present($table, $r), $rows),
        ];
    }

    /**
     * One row in words: hidden columns gone, people named, empty values left out.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,string>
     */
    private function present(string $table, array $row): array
    {
        $out = [];
        foreach ($this->tables->clean($table, $row) as $column => $value) {
            if (in_array($column, self::HIDE, true) || $value === null || $value === '') {
                continue;
            }
            $isPerson = Str::endsWith($column, ['_by', '_user_id']) || in_array($column, ['author_id', 'admitting_doctor_id', 'doctor_id', 'user_id'], true);
            if ($isPerson && is_numeric($value)) {
                $out[Str::headline(Str::beforeLast(Str::beforeLast($column, '_id'), '_user'))] = $this->name((int) $value);

                continue;
            }
            if (Str::endsWith($column, '_id') || Str::endsWith($column, '_type')) {
                continue;
            }
            $out[$column === 'created_at' ? 'Recorded' : Str::headline($column)] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return $out;
    }

    private function name(int $userId): string
    {
        return $this->names[$userId] ??= (string) (User::query()->withoutGlobalScopes()->whereKey($userId)->value('name') ?? 'A member of staff');
    }
}
