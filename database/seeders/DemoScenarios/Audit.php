<?php

namespace Database\Seeders\DemoScenarios;

use App\Models\AuditEntry;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\RecordAccess;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\Audit\AuditTrail;
use App\Support\CurrentHospital;
use Illuminate\Support\Carbon;

/**
 * The audit log, as a demonstrator would show it (docs/audit.md).
 *
 * Seeding the demo already fills the change log (every record created is an
 * entry, by "System"). What it cannot produce is the people: so this adds
 * who opened which patient's record over its latest visits, the way a day really
 * runs (the doctor opens the record at the visit, the desk prints a
 * statement), and two corrections made by named staff whose "after" values
 * are what the record says today, so the trail and the record agree.
 *
 * Idempotent: a hospital that already has read entries is left alone.
 */
final class Audit
{
    public static function run(Hospital $hospital): void
    {
        $current = app(CurrentHospital::class);
        $before = $current->id();
        $current->set($hospital->id);

        try {
            if (RecordAccess::query()->exists()) {
                return;
            }

            $staff = User::query()->where('hospital_id', $hospital->id)->where('is_active', true)->get(['id', 'name', 'role'])->groupBy('role');
            $doctor = $staff->get('doctor')?->first();
            $nurse = $staff->get('nurse')?->first();
            $desk = $staff->get('receptionist')?->first();
            $admin = $staff->get('hospital_admin')?->first();
            if ($doctor === null || $desk === null) {
                return;
            }

            // The doctor opened each recent visit's patient at the visit; the
            // nurse looked again later that day; the desk printed a report.
            $visits = Visit::query()->with('patient')->whereNotNull('patient_id')
                ->latest('id')->limit(12)->get();

            foreach ($visits as $i => $visit) {
                if ($visit->patient === null) {
                    continue;
                }
                $at = Carbon::parse($visit->created_at)->addMinutes(12);
                self::open($visit->patient, $doctor, 'opened', null, $at, 'web', 'Chrome on Windows');
                if ($nurse !== null && $i % 2 === 0) {
                    self::open($visit->patient, $nurse, 'opened', null, $at->copy()->addHours(2), 'api', 'True-Doctor app 1.4.0');
                }
                if ($i % 3 === 0) {
                    self::open($visit->patient, $desk, 'printed', 'Visit report '.$visit->visit_no, $at->copy()->addMinutes(40), 'web', 'Chrome on Windows');
                }
            }

            // Two corrections by named staff, each ending at today's value.
            $patient = Patient::query()->whereNotNull('phone_1')->oldest('id')->first();
            if ($patient !== null) {
                self::change($patient, $desk, ['phone_1' => $patient->phone_1], ['phone_1' => self::typo((string) $patient->phone_1)], now()->subDays(3)->setTime(9, 14));
            }

            $service = Service::query()->where('price', '>', 0)->oldest('id')->first();
            if ($service !== null && $admin !== null) {
                $was = bcmul((string) $service->price, '0.9', 0).'.00';
                self::change($service, $admin, ['price' => (string) $service->price], ['price' => $was], now()->subDays(20)->setTime(16, 5));
            }
        } finally {
            $current->set($before);
        }
    }

    private static function open(Patient $patient, User $user, string $action, ?string $context, Carbon $at, string $channel, string $device): void
    {
        if ($at->isFuture()) {
            $at = now()->subMinutes(5);
        }

        RecordAccess::create([
            'hospital_id' => $patient->hospital_id,
            'user_id' => $user->id,
            'patient_id' => $patient->id,
            'action' => $action,
            'channel' => $channel,
            'context' => $context,
            'ip' => '192.168.1.'.(20 + $user->id % 200),
            'device' => $device,
            'created_at' => $at,
        ]);
    }

    /**
     * @param  array<string,mixed>  $new
     * @param  array<string,mixed>  $old
     */
    private static function change(\Illuminate\Database\Eloquent\Model $record, User $by, array $new, array $old, Carbon $at): void
    {
        $entry = new AuditEntry;
        $entry->forceFill([
            'hospital_id' => $record->getAttribute('hospital_id'),
            'log_name' => AuditTrail::logName($record::class),
            'description' => 'updated',
            'event' => 'updated',
            'subject_type' => $record->getMorphClass(),
            'subject_id' => $record->getKey(),
            'causer_type' => $by->getMorphClass(),
            'causer_id' => $by->id,
            'properties' => collect(['attributes' => $new, 'old' => $old, 'via' => 'web', 'ip' => '192.168.1.'.(20 + $by->id % 200)]),
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();
    }

    /** The number as it was mistyped at registration: two digits swapped. */
    private static function typo(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 4) {
            return $phone.'0';
        }

        $swapped = substr($phone, 0, $len - 2).$phone[$len - 1].$phone[$len - 2];

        return $swapped !== $phone ? $swapped : substr($phone, 0, -1).((int) $phone[$len - 1] + 1) % 10;
    }
}
