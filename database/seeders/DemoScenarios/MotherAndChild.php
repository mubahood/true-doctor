<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\PatientSex;
use App\Enums\PatientStatus;
use App\Enums\PregnancyStatus;
use App\Models\GrowthMeasurement;
use App\Models\Hospital;
use App\Models\Immunisation;
use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\User;
use App\Models\Visit;
use App\Services\GrowthService;
use App\Services\ImmunisationService;
use App\Services\PregnancyService;
use App\Support\CurrentHospital;
use App\Support\MotherChild\WhoGrowth;
use Illuminate\Support\Carbon;

/**
 * Maternity and child health on the demo hospital (docs/mother-child.md).
 *
 * Only on people it fits: pregnancies on women of 18 to 40 already on the
 * register (those booked for an antenatal review first), immunisation and
 * growth on children under five. Through the services, deterministic, and
 * idempotent: a woman who already has a pregnancy, a dose already given and
 * a day already measured are left as they are.
 *
 * What it shows: a pregnancy at about 28 weeks with its contacts on time, one
 * booked late at 16 weeks (contact 1 and its tests missed), one delivered
 * eight months ago; a child fully up to date, one whose 14-week doses are
 * overdue and whose last weighing shows no gain, and one growing on the low side.
 */
final class MotherAndChild
{
    public static function run(Hospital $hospital): void
    {
        $context = app(CurrentHospital::class);
        $was = $context->id();
        $context->set($hospital->id);

        try {
            $by = User::query()->where('hospital_id', $hospital->id)->whereIn('role', ['nurse', 'doctor'])->orderBy('id')->first()
                ?? User::query()->where('hospital_id', $hospital->id)->orderBy('id')->first();
            if ($by === null) {
                return;
            }

            self::pregnancies($by);
            self::children($by);
        } finally {
            $context->set($was);
        }
    }

    private static function pregnancies(User $by): void
    {
        $today = Carbon::today();
        $women = Patient::query()
            ->where('sex', PatientSex::Female->value)->where('status', PatientStatus::Active->value)
            ->whereNotNull('dob')
            ->whereBetween('dob', [$today->copy()->subYears(41)->addDay()->toDateString(), $today->copy()->subYears(18)->toDateString()])
            ->addSelect(['patients.*', 'antenatal' => Visit::query()->selectRaw('count(*)')->whereColumn('visits.patient_id', 'patients.id')->where('reason', 'like', 'Antenatal%')])
            ->orderByDesc('antenatal')->orderBy('id')->limit(3)->get();

        $service = app(PregnancyService::class);
        $plans = [
            // weeks pregnant today (null: delivered about eight months ago), history, contacts at weeks, risks
            ['weeks' => 28, 'gravida' => 3, 'para' => 2, 'contacts' => [11, 19, 26], 'risks' => []],
            ['weeks' => 16, 'gravida' => 1, 'para' => 0, 'contacts' => [16], 'risks' => ['anaemia']],
            ['weeks' => null, 'gravida' => 2, 'para' => 1, 'contacts' => [12, 20, 26, 30, 34, 36], 'risks' => ['previous_caesarean']],
        ];

        foreach ($women->values() as $i => $woman) {
            if (Pregnancy::query()->where('patient_id', $woman->id)->exists()) {
                continue;
            }
            $plan = $plans[$i];
            $lmp = $plan['weeks'] === null ? $today->copy()->subDays(280 + 240) : $today->copy()->subWeeks($plan['weeks'])->subDays(2);

            // Each step is recorded on its own day, as it happened, so the
            // service's dating and "not in the future" rules hold throughout.
            try {
                Carbon::setTestNow($lmp->copy()->addWeeks($plan['contacts'][0])->setTime(9, 0));
                $pregnancy = $service->open($woman, [
                    'lmp' => $lmp->toDateString(), 'gravida' => $plan['gravida'], 'para' => $plan['para'],
                    'risk_factors' => $plan['risks'], 'birth_place' => 'This hospital',
                    'birth_companion' => 'Her mother', 'transport' => 'Boda boda, arranged with a neighbour',
                ], $by);

                foreach ($plan['contacts'] as $n => $week) {
                    Carbon::setTestNow($lmp->copy()->addWeeks($week)->setTime(10, 0));
                    $checks = ['bp', 'weight', 'ifas'];
                    if ($n === 0) {
                        $checks = array_merge($checks, $week <= 12 ? ['urine', 'hb', 'hiv', 'syphilis', 'hepb', 'blood_group', 'ultrasound', 'td', 'llin'] : ['hiv', 'syphilis', 'td', 'iptp']);
                    } else {
                        $checks = array_merge($checks, ['fundal_height', 'iptp'], $week >= 26 ? ['fetal_heart'] : [], $n === 1 ? ['td', 'deworming'] : []);
                    }
                    $service->recordContact($pregnancy, [
                        'blood_pressure' => (110 + ($n * 3) % 15).'/'.(70 + ($n * 2) % 10),
                        'weight' => 58 + $week * 0.35 + $i * 4,
                        'fundal_height' => $week >= 20 ? $week - 1 : null,
                        'fetal_heart_rate' => $week >= 20 ? 140 + $n * 2 : null,
                        'checks' => $checks,
                    ], null, $by);
                }

                if ($plan['weeks'] === null) {
                    Carbon::setTestNow($lmp->copy()->addDays(275)->setTime(16, 0));
                    $service->close($pregnancy, PregnancyStatus::Delivered, Carbon::today(), 'Live baby girl, 3.2 kg.', $by);
                }
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    private static function children(User $by): void
    {
        $today = Carbon::today();
        $children = Patient::query()
            ->where('status', PatientStatus::Active->value)
            ->whereNotNull('dob')->whereNotNull('sex')
            ->where('dob', '>', $today->copy()->subYears(5)->toDateString())
            ->orderByDesc('dob')->orderBy('id')->limit(3)->get();

        $immunisation = app(ImmunisationService::class);
        $growth = app(GrowthService::class);

        foreach ($children->values() as $i => $child) {
            $dob = $child->dob->copy()->startOfDay();
            $given = Immunisation::query()->standing()->where('patient_id', $child->id)->pluck('dose_key')->all();

            // The second child missed the 14-week visit: those doses and the
            // later ones stay overdue.
            foreach (ImmunisationService::scheduleFor($child->sex) as $key => $dose) {
                if (in_array($key, $given, true) || ($i === 1 && $dose['age_days'] >= 98)) {
                    continue;
                }
                $on = $dob->copy()->addDays($dose['age_days'] + ($dose['age_days'] === 0 ? 0 : 3));
                if ($on->greaterThan($today)) {
                    continue;
                }
                $immunisation->record($child, [[
                    'dose_key' => $key, 'given_on' => $on->toDateString(),
                    'batch_no' => 'UG-'.strtoupper($key).'-'.(2400 + $dose['age_days']),
                ]], null, $by);
            }

            // Weighed every two months along a z-score line; the second
            // child's last weighing shows no gain (faltering).
            $male = $child->sex === PatientSex::Male;
            $z = [0.2, -0.6, -1.6][$i] ?? 0.0;
            $months = [];
            for ($m = 0; $m <= WhoGrowth::MAX_MONTHS && $dob->copy()->addDays((int) round($m * WhoGrowth::DAYS_PER_MONTH))->lessThanOrEqualTo($today); $m += 2) {
                $months[] = $m;
            }
            $previous = null;
            foreach ($months as $n => $m) {
                $days = (int) round($m * WhoGrowth::DAYS_PER_MONTH);
                $on = $dob->copy()->addDays($days);
                $last = $n === count($months) - 1;
                $weight = $i === 1 && $last && $previous !== null ? $previous : (float) WhoGrowth::weightAt($z, $days, $male);
                $previous = $weight;
                if (GrowthMeasurement::query()->where('patient_id', $child->id)->whereDate('measured_on', $on->toDateString())->exists()) {
                    continue;
                }
                $growth->record($child, [
                    'measured_on' => $on->toDateString(),
                    'weight' => number_format($weight, 2, '.', ''),
                    'height' => number_format((float) WhoGrowth::heightAt($z, $days, $male), 1, '.', ''),
                    'muac' => $m >= 6 ? ($i === 1 && $last ? '12.2' : '14.0') : null,
                ], null, $by);
            }
        }
    }
}
