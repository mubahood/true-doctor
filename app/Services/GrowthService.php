<?php

namespace App\Services;

use App\Enums\PatientSex;
use App\Models\GrowthMeasurement;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\MotherChild\MotherChild;
use App\Support\MotherChild\WhoGrowth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The growth chart (roadmap 04 §4): weight, length/height and MUAC for a
 * child under five, read against the WHO Child Growth Standards
 * (docs/mother-child.md).
 *
 * The points are what a child clinic measured (growth_measurements) and the
 * weights and heights already taken as vitals on visits, one point a day (the
 * clinic's measurement wins on a day that has both): nothing is typed twice.
 * Each point carries its weight-for-age and length/height-for-age z-scores and
 * band; the chart flags faltering (no weight gained over a month, or a fall
 * across a centile line).
 */
class GrowthService
{
    public static function onPatient(Patient $patient, ?Hospital $hospital, ?User $user): bool
    {
        if (! MotherChild::canView($user)) {
            return false;
        }
        if (GrowthMeasurement::query()->where('patient_id', $patient->id)->exists()) {
            return true;
        }

        return $patient->dob !== null
            && $patient->dob->age < (int) config('mother_child.growth_age_years')
            && MotherChild::practises($hospital, ['paediatrics', 'maternity']);
    }

    /**
     * Every point, oldest first, with its z-scores and bands.
     *
     * @return list<array<string,mixed>>
     */
    public function points(Patient $patient): array
    {
        $byDay = [];

        $visits = Visit::query()->where('patient_id', $patient->id)->whereNotNull('vitals_recorded_at')
            ->where(fn ($q) => $q->whereNotNull('weight')->orWhereNotNull('height'))
            ->orderBy('vitals_recorded_at')->limit(500)
            ->get(['id', 'uuid', 'weight', 'height', 'vitals_recorded_at']);
        foreach ($visits as $v) {
            $day = $v->vitals_recorded_at->toDateString();
            $byDay[$day] = [
                'date' => $day,
                'weight' => $v->weight === null ? null : number_format((float) $v->weight, 2, '.', ''),
                'height' => $v->height === null ? null : number_format((float) $v->height, 1, '.', ''),
                'muac' => null, 'oedema' => false, 'source' => 'visit', 'uuid' => (string) $v->uuid,
            ];
        }

        $measured = GrowthMeasurement::query()->where('patient_id', $patient->id)->orderBy('measured_on')->orderBy('id')->limit(500)->get();
        foreach ($measured as $m) {
            $day = $m->measured_on->toDateString();
            $was = $byDay[$day] ?? null;
            $byDay[$day] = [
                'date' => $day,
                'weight' => $m->weight ?? $was['weight'] ?? null,
                'height' => $m->height ?? $was['height'] ?? null,
                'muac' => $m->muac,
                'oedema' => $m->oedema,
                'source' => 'clinic',
                'uuid' => $m->uuid,
            ];
        }
        ksort($byDay);

        $male = $patient->sex === PatientSex::Male;
        $dob = $patient->dob;
        $points = [];
        foreach ($byDay as $p) {
            $age = $dob === null ? null : MotherChild::days($dob, Carbon::parse($p['date']));
            $waz = $age !== null && $p['weight'] !== null && $patient->sex !== null ? WhoGrowth::weightForAge((float) $p['weight'], $age, $male) : null;
            $haz = $age !== null && $p['height'] !== null && $patient->sex !== null ? WhoGrowth::heightForAge((float) $p['height'], $age, $male) : null;
            $points[] = $p + [
                'age_days' => $age,
                'age_label' => $age === null ? null : MotherChild::ageLabel($age),
                'weight_z' => $waz === null ? null : round($waz, 2),
                'height_z' => $haz === null ? null : round($haz, 2),
                'weight_band' => $waz === null ? null : self::weightBand($waz),
                'height_band' => $haz === null ? null : self::heightBand($haz),
                'muac_band' => $p['muac'] === null || $age === null ? null : self::muacBand((float) $p['muac'], $age),
            ];
        }

        return $points;
    }

    /**
     * Where the child stands now: the latest weight, length/height and MUAC
     * with their bands, and whether growth is faltering.
     *
     * @param  list<array<string,mixed>>|null  $points
     * @return array<string,mixed>
     */
    public function assessment(Patient $patient, ?array $points = null): array
    {
        $points ??= $this->points($patient);
        $latest = fn (string $field) => collect($points)->filter(fn ($p) => $p[$field] !== null)->last();
        $weight = $latest('weight');
        $height = $latest('height');
        $muac = $latest('muac');
        $oedema = (bool) (collect($points)->last()['oedema'] ?? false);

        return [
            'points' => count($points),
            'weight' => $weight === null ? null : ['value' => (string) $weight['weight'], 'date' => $weight['date'], 'z' => $weight['weight_z'], 'band' => $weight['weight_band']],
            'height' => $height === null ? null : ['value' => (string) $height['height'], 'date' => $height['date'], 'z' => $height['height_z'], 'band' => $height['height_band']],
            'muac' => $muac === null ? null : ['value' => (string) $muac['muac'], 'date' => $muac['date'], 'band' => $muac['muac_band']],
            'oedema' => $oedema,
            'faltering' => $this->faltering($points),
        ];
    }

    /**
     * Faltering: no weight gained since a weighing at least a month before
     * the latest, or the weight-for-age z-score fell across a centile line.
     *
     * @param  list<array<string,mixed>>  $points
     * @return array{flag:bool,reason:?string}
     */
    public function faltering(array $points): array
    {
        $weighed = array_values(array_filter($points, fn ($p) => $p['weight'] !== null));
        $last = end($weighed);
        if ($last === false || count($weighed) < 2) {
            return ['flag' => false, 'reason' => null];
        }
        $days = (int) config('mother_child.growth.faltering_days');
        $drop = (float) config('mother_child.growth.faltering_z_drop');

        $before = null;
        foreach (array_reverse(array_slice($weighed, 0, -1)) as $p) {
            if (MotherChild::days(Carbon::parse($p['date']), Carbon::parse($last['date'])) >= $days) {
                $before = $p;
                break;
            }
        }
        if ($before !== null && bccomp((string) $last['weight'], (string) $before['weight'], 2) <= 0) {
            return ['flag' => true, 'reason' => 'No weight gained since '.Carbon::parse($before['date'])->format('d M Y').'.'];
        }
        $previous = $weighed[count($weighed) - 2];
        if ($last['weight_z'] !== null && $previous['weight_z'] !== null && $drop <= $previous['weight_z'] - $last['weight_z']) {
            return ['flag' => true, 'reason' => 'Weight-for-age fell from '.self::z($previous['weight_z']).' to '.self::z($last['weight_z']).'.'];
        }

        return ['flag' => false, 'reason' => null];
    }

    /** The WHO weight-for-age lines a chart draws: −3, −2, the median and +2. */
    public const REFERENCE_Z = [-3, -2, 0, 2];

    /**
     * What a weight-for-age chart is drawn on: the WHO lines (kg at each
     * month from birth), from birth to a little past the latest weighing (a
     * year at least, five years at most), and a top for the kg axis that
     * fits the +3 line and every weighing. Null without a date of birth and a
     * sex. The web's chart and the app's read the same answer.
     *
     * @param  list<array<string,mixed>>  $points  as points() gives them
     * @return array{months:int, kg_max:float, lines:list<array{z:int, points:list<array{0:int, 1:float}>}>}|null
     */
    public function weightReference(Patient $patient, array $points): ?array
    {
        if ($patient->dob === null || $patient->sex === null) {
            return null;
        }
        $male = $patient->sex === PatientSex::Male;
        $weighed = self::chartable($points);
        $lastMonths = $weighed === [] ? 0 : end($weighed)['age_days'] / WhoGrowth::DAYS_PER_MONTH;
        $months = (int) min(WhoGrowth::MAX_MONTHS, max(12, (int) ceil(($lastMonths + 3) / 6) * 6));
        $at = fn (float $z, int $m) => WhoGrowth::weightAt($z, (int) round($m * WhoGrowth::DAYS_PER_MONTH), $male);

        $lines = [];
        foreach (self::REFERENCE_Z as $z) {
            $line = [];
            for ($m = 0; $m <= $months; $m++) {
                $line[] = [$m, round((float) $at($z, $m), 2)];
            }
            $lines[] = ['z' => $z, 'points' => $line];
        }

        return [
            'months' => $months,
            'kg_max' => (float) ceil(max($at(3, $months) ?? 0, ...array_map(fn ($p) => (float) $p['weight'], $weighed ?: [['weight' => 0]])) + 1),
            'lines' => $lines,
        ];
    }

    /**
     * The weighings a weight-for-age chart can place: weighed, with an age,
     * and within the WHO tables (five years).
     *
     * @param  list<array<string,mixed>>  $points
     * @return list<array<string,mixed>>
     */
    public static function chartable(array $points): array
    {
        return array_values(array_filter($points, fn ($p) => $p['weight'] !== null && $p['age_days'] !== null
            && $p['age_days'] <= WhoGrowth::MAX_MONTHS * WhoGrowth::DAYS_PER_MONTH));
    }

    /**
     * Record a measurement at a child clinic. Append-only; a repeated `uuid`
     * is recorded once.
     *
     * @param  array<string,mixed>  $data  measured_on, weight, height, muac, oedema, notes, uuid
     */
    public function record(Patient $patient, array $data, ?Visit $visit, User $by): GrowthMeasurement
    {
        if (! empty($data['uuid']) && ($made = GrowthMeasurement::query()->where('uuid', $data['uuid'])->where('patient_id', $patient->id)->first())) {
            return $made;
        }

        $on = ! empty($data['measured_on']) ? Carbon::parse((string) $data['measured_on'])->startOfDay() : Carbon::today();
        $errors = [];
        if ($on->isFuture() || ($patient->dob !== null && $on->lessThan($patient->dob->copy()->startOfDay()))) {
            $errors['measured_on'] = 'The date must be between the birth and today.';
        }
        $read = function (string $key, float $min, float $max, string $message) use ($data, &$errors): ?string {
            $v = $data[$key] ?? null;
            if ($v === null || $v === '') {
                return null;
            }
            if (! is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                $errors[$key] = $message;

                return null;
            }

            return (string) $v;
        };
        $weight = $read('weight', 0.5, 80, 'Weight must be between 0.5 and 80 kg.');
        $height = $read('height', 35, 150, 'Length or height must be between 35 and 150 cm.');
        $muac = $read('muac', 6, 30, 'MUAC must be between 6 and 30 cm.');
        if ($weight === null && $height === null && $muac === null && $errors === []) {
            $errors['weight'] = 'Enter a weight, a length or height, or a MUAC.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return GrowthMeasurement::create([
            'uuid' => $data['uuid'] ?? (string) Str::uuid(),
            'patient_id' => $patient->id,
            'visit_id' => $visit?->id,
            'measured_on' => $on->toDateString(),
            'weight' => $weight === null ? null : bcadd($weight, '0', 2),
            'height' => $height === null ? null : bcadd($height, '0', 1),
            'muac' => $muac === null ? null : bcadd($muac, '0', 1),
            'oedema' => (bool) ($data['oedema'] ?? false),
            'notes' => Str::limit(trim((string) ($data['notes'] ?? '')), 500, '') ?: null,
            'recorded_by' => $by->id,
        ]);
    }

    /** @return array{key:string,label:string,tone:string} */
    public static function weightBand(float $z): array
    {
        return match (true) {
            $z < -3 => ['key' => 'severely_underweight', 'label' => 'Severely underweight', 'tone' => 'danger'],
            $z < -2 => ['key' => 'underweight', 'label' => 'Underweight', 'tone' => 'warn'],
            $z > 2 => ['key' => 'above_range', 'label' => 'Above the normal range', 'tone' => 'info'],
            default => ['key' => 'normal', 'label' => 'Normal', 'tone' => 'success'],
        };
    }

    /** @return array{key:string,label:string,tone:string} */
    public static function heightBand(float $z): array
    {
        return match (true) {
            $z < -3 => ['key' => 'severely_stunted', 'label' => 'Severely stunted', 'tone' => 'danger'],
            $z < -2 => ['key' => 'stunted', 'label' => 'Stunted', 'tone' => 'warn'],
            $z > 3 => ['key' => 'very_tall', 'label' => 'Very tall', 'tone' => 'info'],
            default => ['key' => 'normal', 'label' => 'Normal', 'tone' => 'success'],
        };
    }

    /**
     * MUAC is read for children 6 to 59 months only.
     *
     * @return array{key:string,label:string,tone:string}|null
     */
    public static function muacBand(float $cm, int $ageDays): ?array
    {
        if ($ageDays < 183 || $ageDays >= 1826) {
            return null;
        }
        $cut = config('mother_child.growth.muac');

        return match (true) {
            $cm < $cut['severe'] => ['key' => 'severe', 'label' => 'Severe acute malnutrition', 'tone' => 'danger'],
            $cm < $cut['moderate'] => ['key' => 'moderate', 'label' => 'Moderate acute malnutrition', 'tone' => 'warn'],
            default => ['key' => 'normal', 'label' => 'Normal', 'tone' => 'success'],
        };
    }

    public static function z(?float $z): string
    {
        return $z === null ? '—' : ($z > 0 ? '+' : '').number_format($z, 1);
    }
}
