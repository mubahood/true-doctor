<?php

namespace App\Support;

use App\Models\Bed;
use App\Models\Department;
use App\Models\DiagnosisCode;
use App\Models\District;
use App\Models\DoctorSchedule;
use App\Models\InsuranceProvider;
use App\Models\LabTest;
use App\Models\RadiologyStudy;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * "Has the catalogue changed?" - one revision number per reference section.
 *
 * Reference data (wards, services, lab tests, doctors...) is read by the app in
 * bulk and changes rarely. The app asks `GET /bootstrap?since=<revision>` and
 * should get back only the sections that moved. This class keeps the number
 * for each section.
 *
 * ── Drawn from the sync counter, bumped by observers ────────────────────────
 * A section's revision is a value from {@see SyncRevision::next()} taken when a
 * model in that section was created, changed in a column the section shows, or
 * deleted/restored. It is therefore on the same scale as the pull cursor and
 * the `revision` bootstrap returns, so "section revision > since" is a
 * well-defined question. Nothing here runs on a schedule: a standalone copy's
 * cron fires every 19 minutes, and a catalogue edit must show on the next
 * request, not the next tick.
 *
 * ── Kept in the cache, safe to lose ─────────────────────────────────────────
 * The numbers live in the default cache store (the file store on a standalone
 * copy). If the cache is cleared, a missing section reads as the hospital's
 * CURRENT counter - never smaller than anything issued before - so every
 * client sees "changed" once and refetches, and from then on the value is
 * stable. Losing the cache costs one refetch and can never hide a change.
 *
 * ── Global sections ─────────────────────────────────────────────────────────
 * `districts` has no hospital; `payment_methods` is an enum in code. They keep
 * a global TOKEN (a counter, or a hash of the content); a hospital's revision
 * for the section is minted from its own counter the first time it notices the
 * token moved.
 */
final class ReferenceRevision
{
    /**
     * section => [model class => columns whose change matters, or null for any].
     * A save that touches none of the listed columns (a stock balance, a bed
     * `updated_at`) does not bump anything.
     *
     * @var array<string, array<class-string<Model>, list<string>|null>>
     */
    public const WATCH = [
        'wards' => [Ward::class => ['name', 'is_active']],
        'beds' => [Bed::class => ['ward_id', 'name', 'status', 'is_active']],
        'services' => [Service::class => ['name', 'code', 'price', 'is_active']],
        'lab_tests' => [LabTest::class => ['name', 'specimen', 'unit', 'reference_range', 'price', 'is_active']],
        // A balance is not reference data: only what names an item.
        'medications' => [StockItem::class => ['name', 'unit', 'sku', 'is_active']],
        'doctors' => [
            User::class => ['name', 'role', 'sees_patients', 'is_active', 'hospital_id'],
            StaffProfile::class => ['department_id', 'job_title', 'specialty', 'is_active'],
            DoctorSchedule::class => null,
        ],
        'departments' => [Department::class => ['name', 'code', 'is_active']],
        'stock_categories' => [StockCategory::class => ['name', 'unit', 'is_active']],
        'insurance_providers' => [InsuranceProvider::class => ['name', 'code', 'is_active']],
        'radiology_studies' => [RadiologyStudy::class => ['name', 'modality', 'body_part', 'price', 'is_active']],
        'districts' => [District::class => null],
        // The diagnosis catalogue (docs/api/diagnoses.md): shared, like districts.
        'diagnoses' => [DiagnosisCode::class => ['code', 'title', 'synonyms', 'is_active']],
    ];

    /** Sections with no hospital behind them. */
    public const GLOBAL = ['districts', 'payment_methods', 'diagnoses'];

    /** Every section the server knows, in the order they are sent. @return list<string> */
    public static function sections(): array
    {
        return array_values(array_unique([...array_keys(self::WATCH), 'payment_methods']));
    }

    /** Register the observers. Called once from the service provider. */
    public static function observe(): void
    {
        foreach (self::WATCH as $section => $models) {
            foreach (array_keys($models) as $model) {
                $model::observe(\App\Observers\ReferenceRevisionObserver::class);
            }
        }
    }

    /** Which sections a change to this model class can affect. @return list<string> */
    public static function sectionsFor(string $modelClass): array
    {
        $found = [];

        foreach (self::WATCH as $section => $models) {
            if (array_key_exists($modelClass, $models)) {
                $found[] = $section;
            }
        }

        return $found;
    }

    /** The columns of $modelClass that matter to $section (null = any). @return list<string>|null */
    public static function columnsFor(string $section, string $modelClass): ?array
    {
        return self::WATCH[$section][$modelClass] ?? null;
    }

    /** Record that a section changed. A null hospital (console, seeder) is ignored. */
    public static function bump(string $section, ?int $hospitalId): void
    {
        if (in_array($section, self::GLOBAL, true)) {
            Cache::forever(self::tokenKey($section), ((int) Cache::get(self::tokenKey($section), 0)) + 1);

            return;
        }

        if ($hospitalId === null) {
            return;
        }

        Cache::forever(self::key($hospitalId, $section), SyncRevision::next($hospitalId));
    }

    /**
     * Every section's revision for one hospital.
     *
     * @return array<string,int>
     */
    public static function all(?int $hospitalId): array
    {
        $out = [];

        foreach (self::sections() as $section) {
            $out[$section] = self::of($section, $hospitalId);
        }

        return $out;
    }

    public static function of(string $section, ?int $hospitalId): int
    {
        if ($hospitalId === null) {
            return 0;
        }

        if (in_array($section, self::GLOBAL, true)) {
            return self::ofGlobal($section, $hospitalId);
        }

        $key = self::key($hospitalId, $section);
        $stored = Cache::get($key);

        if (is_int($stored)) {
            return $stored;
        }

        // Unknown (cache cleared, or never changed since the cache began):
        // the counter itself is >= anything ever issued, so this reads as
        // "changed" to every client exactly once. `add` keeps it stable after.
        $current = SyncRevision::current($hospitalId);
        Cache::add($key, $current);

        $now = Cache::get($key);

        return is_int($now) ? $now : $current;
    }

    private static function ofGlobal(string $section, int $hospitalId): int
    {
        $token = self::token($section);
        $key = self::key($hospitalId, $section);
        $seen = Cache::get($key);

        if (is_array($seen) && ($seen['token'] ?? null) === $token) {
            return (int) $seen['revision'];
        }

        // First time this hospital sees this token (or the cache was cleared):
        // mint a revision from its own counter so the section reads as changed.
        $revision = SyncRevision::next($hospitalId);
        Cache::forever($key, ['token' => $token, 'revision' => $revision]);

        return $revision;
    }

    private static function token(string $section): string
    {
        if ($section === 'payment_methods') {
            // Content-addressed: editing the enum changes the token on deploy.
            return md5(json_encode(self::paymentMethods()) ?: '');
        }

        return (string) Cache::get(self::tokenKey($section), 0);
    }

    /**
     * The ways a payment can be recorded, as reference rows (every row has an
     * integer `id`: an installed app stores reference rows by it).
     *
     * @return list<array{id:int, value:string, label:string, needs_reference:bool}>
     */
    public static function paymentMethods(): array
    {
        $rows = [];

        foreach (\App\Enums\PaymentMethod::recordable() as $i => $method) {
            $rows[] = [
                'id' => $i + 1,
                'value' => $method->value,
                'label' => $method->label(),
                'needs_reference' => $method->needsReference(),
            ];
        }

        return $rows;
    }

    private static function key(int $hospitalId, string $section): string
    {
        return "reference-revision:{$hospitalId}:{$section}";
    }

    private static function tokenKey(string $section): string
    {
        return "reference-revision:global:{$section}";
    }
}
