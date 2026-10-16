<?php

namespace App\Support;

use App\Livewire\Departments\Index as Departments;
use App\Livewire\LabTests\Index as LabTests;
use App\Livewire\RadiologyStudies\Index as RadiologyStudies;
use App\Livewire\Services\Index as Services;
use App\Livewire\Stock\Index as Stock;
use App\Livewire\StockCategories\Index as StockCategories;
use App\Livewire\Wards\Index as Wards;
use App\Models\Department;
use App\Models\LabTest;
use App\Models\RadiologyStudy;
use App\Models\Service;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\Ward;
use App\Support\Practice\Practice;
use Illuminate\Support\Facades\DB;

/**
 * The starter-data imports of the catalogue pages (trait ImportsSamples),
 * available without a browser.
 *
 * The rows come from SampleCatalogue (the practice's own template) and each is
 * saved by the SAME `persistSample()` the web modal calls - on the page's own
 * Livewire class, so a fix to how a ward or a drug is created is a fix for
 * both. Rows whose name already exists are left alone (as the modal does), so
 * an import can be repeated safely.
 */
final class StarterData
{
    /**
     * kind => [label, model, page class, source, practice module, web route]
     *
     * @var array<string,array{label:string,model:class-string,host:class-string,source:string,module:?string,route:string,noun:string}>
     */
    public const KINDS = [
        'departments' => ['label' => 'Departments', 'noun' => 'department', 'model' => Department::class, 'host' => Departments::class, 'source' => 'departments', 'module' => null, 'route' => 'admin.departments.index'],
        'services' => ['label' => 'Price list', 'noun' => 'service', 'model' => Service::class, 'host' => Services::class, 'source' => 'services', 'module' => null, 'route' => 'admin.services.index'],
        'wards' => ['label' => 'Wards', 'noun' => 'ward', 'model' => Ward::class, 'host' => Wards::class, 'source' => 'wards', 'module' => 'inpatient', 'route' => 'admin.wards.index'],
        'lab_tests' => ['label' => 'Lab tests', 'noun' => 'lab test', 'model' => LabTest::class, 'host' => LabTests::class, 'source' => 'labTests', 'module' => 'laboratory', 'route' => 'admin.lab-tests.index'],
        'radiology_studies' => ['label' => 'Radiology studies', 'noun' => 'study', 'model' => RadiologyStudy::class, 'host' => RadiologyStudies::class, 'source' => 'radiologyStudies', 'module' => 'radiology', 'route' => 'admin.radiology-studies.index'],
        'stock_categories' => ['label' => 'Stock categories', 'noun' => 'category', 'model' => StockCategory::class, 'host' => StockCategories::class, 'source' => 'stockCategories', 'module' => 'pharmacy', 'route' => 'admin.stock-categories.index'],
        'stock_items' => ['label' => 'Stock items', 'noun' => 'item', 'model' => StockItem::class, 'host' => Stock::class, 'source' => 'stockItems', 'module' => 'pharmacy', 'route' => 'admin.stock.index'],
    ];

    /** Kinds this practice offers, in menu order. @return list<string> */
    public static function availableFor(Practice $practice): array
    {
        return array_values(array_filter(
            array_keys(self::KINDS),
            fn (string $k) => self::KINDS[$k]['module'] === null || $practice->uses(self::KINDS[$k]['module']),
        ));
    }

    /** @return list<array<string,mixed>> the sample rows for a kind, from the practice's template */
    public static function rows(string $kind): array
    {
        return SampleCatalogue::{self::KINDS[$kind]['source']}();
    }

    /**
     * Sample rows whose name does not exist yet (the modal's own filter).
     *
     * @return list<array<string,mixed>>
     */
    public static function remaining(string $kind): array
    {
        $existing = self::KINDS[$kind]['model']::query()
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->all();

        return array_values(array_filter(
            self::rows($kind),
            fn ($r) => ! in_array(mb_strtolower(trim((string) $r['name'])), $existing, true),
        ));
    }

    /**
     * Save the remaining rows (or only those named), each through the page's
     * own persistSample().
     *
     * @param  list<string>|null  $names  null: everything that is left
     */
    public static function import(string $kind, ?array $names = null): int
    {
        $rows = self::remaining($kind);

        if ($names !== null) {
            $wanted = array_map(fn ($n) => mb_strtolower(trim((string) $n)), $names);
            $rows = array_values(array_filter($rows, fn ($r) => in_array(mb_strtolower(trim((string) $r['name'])), $wanted, true)));
        }

        $host = new (self::KINDS[$kind]['host']);
        // persistSample() is protected on the page; this is the call the modal makes.
        $persist = new \ReflectionMethod($host, 'persistSample');

        DB::transaction(function () use ($rows, $host, $persist) {
            foreach ($rows as $row) {
                if (trim((string) ($row['name'] ?? '')) !== '') {
                    $persist->invoke($host, $row);
                }
            }
        });

        return count($rows);
    }

    /** The web route the app opens natively for an onboarding step. */
    public static function appRouteForStep(string $step, Practice $practice): ?string
    {
        return match ($step) {
            'practice' => 'admin.settings.practice',
            'profile' => 'admin.settings.hospital',
            'billing' => 'admin.settings.billing',
            'departments' => 'admin.departments.index',
            'services' => 'admin.services.index',
            'staff' => 'admin.users.index',
            'rooms' => 'admin.rooms.index',
            'wards' => 'admin.wards.index',
            'catalogues' => $practice->uses('laboratory') ? 'admin.lab-tests.index' : 'admin.stock-categories.index',
            default => null,
        };
    }

    /** The starter-data kind that would fill an onboarding step, if one would. */
    public static function kindForStep(string $step, Practice $practice): ?string
    {
        return match ($step) {
            'departments' => 'departments',
            'services' => 'services',
            'wards' => 'wards',
            'catalogues' => $practice->uses('laboratory') ? 'lab_tests' : 'stock_categories',
            default => null,
        };
    }
}
