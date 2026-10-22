<?php

namespace App\Services;

use App\Enums\BedStatus;
use App\Enums\RoomStatus;
use App\Enums\StockMovementReason;
use App\Models\Bed;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\RadiologyStudy;
use App\Models\Room;
use App\Models\Service;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\Ward;
use App\Services\Finance\ChartOfAccounts;
use App\Support\HospitalSettings;
use App\Support\Practice\Practice;
use App\Support\Practice\VisitForm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Choosing a kind of practice, and setting a hospital up as one
 * (docs/practice.md).
 *
 * `choose()` only records the answer — it changes what is SUGGESTED, never
 * what exists. `apply()` is the one-click setup: it writes the starter data
 * the hospital's template describes, part by part, and only what is missing,
 * so running it twice (or after adding a specialty) adds the new things and
 * leaves alone everything already there — including prices already edited.
 *
 * Every write goes the way the module pages write: stock arrives through
 * StockService as opening stock (so the shelf and the books agree from the
 * first day), and a ward comes with its beds.
 */
class PracticeService
{
    /** What `apply()` can set up, in the order it is offered. */
    public const PARTS = [
        'departments' => 'Departments',
        'services' => 'Price list',
        'rooms' => 'Rooms',
        'wards' => 'Wards and beds',
        'lab_tests' => 'Lab tests',
        'radiology' => 'Imaging studies',
        'stock' => 'Opening stock',
        'accounts' => 'Chart of accounts',
    ];

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  list<string>  $extraPacks
     * @param  list<string>|null  $modules  null: what the sector and its packs switch on
     */
    public function choose(Hospital $hospital, string $sector, array $extraPacks = [], ?array $modules = null): Practice
    {
        $chosen = Practice::sector($sector);

        if ($chosen === null) {
            throw new \DomainException('Choose one of the kinds of practice listed.');
        }

        $packs = array_values(array_diff(Practice::validPacks($extraPacks), $chosen->packs));
        $modules = $modules === null ? null : Practice::validModules($modules);

        $settings = $hospital->settings ?? [];
        $settings['practice'] = [
            'sector' => $chosen->key,
            'packs' => $packs,
            'modules' => $modules,
            'chosen_at' => Carbon::now()->toIso8601String(),
        ];

        $hospital->settings = $settings;
        $hospital->save();

        // The chart learns a new practice's accounts the next time it is asked.
        $this->chart->forget();

        return Practice::of($hospital);
    }

    /**
     * How this hospital writes a visit down (App\Support\Practice\VisitForm):
     * the vital signs it records and how a dental visit is written. Null
     * follows the practice; anything else is the hospital's own choice. Like
     * choosing a practice, it changes what is asked, never what was recorded.
     *
     * The visit report (patient summary or full clinical record) is chosen
     * the same way.
     *
     * @param  list<string>|null  $vitals
     */
    public function chooseVisitForm(Hospital $hospital, ?array $vitals, ?string $dentalNotes, ?string $visitReport = null): VisitForm
    {
        if ($dentalNotes !== null && ! array_key_exists($dentalNotes, VisitForm::DENTAL_NOTES)) {
            throw new \DomainException('Choose simple notes or the detailed case sheet.');
        }
        if ($visitReport !== null && ! array_key_exists($visitReport, VisitForm::VISIT_REPORTS)) {
            throw new \DomainException('Choose the patient summary or the full clinical record.');
        }

        $settings = $hospital->settings ?? [];
        $settings['clinical'] = [
            'vitals' => $vitals === null ? null : VisitForm::validVitals($vitals),
            'dental_notes' => $dentalNotes,
            'visit_report' => $visitReport,
        ];

        $hospital->settings = $settings;
        $hospital->save();

        return VisitForm::of($hospital);
    }

    /**
     * What applying would add, per part: the rows of the template the hospital
     * does not have yet. Parts for a module the practice does not use are left
     * out — a dental practice is not offered wards.
     *
     * @return array<string, array{label:string,new:int,total:int}>
     */
    public function preview(Practice $practice): array
    {
        $out = [];

        foreach ($this->sources($practice) as $part => [$rows, $model]) {
            if ($part === 'accounts') {
                $out[$part] = ['label' => self::PARTS[$part], 'new' => 0, 'total' => count($rows)];

                continue;
            }

            $have = $this->names($model);
            $new = count(array_filter($rows, fn (array $r) => ! in_array(mb_strtolower($r['name']), $have, true)));
            $out[$part] = ['label' => self::PARTS[$part], 'new' => $new, 'total' => count($rows)];
        }

        return $out;
    }

    /**
     * Set the hospital up from its template.
     *
     * @param  list<string>  $parts  which of PARTS to write; empty means all that apply
     * @return array<string,int> part => rows created
     */
    public function apply(Practice $practice, array $parts = [], ?int $by = null): array
    {
        $sources = $this->sources($practice);
        $wanted = $parts === [] ? array_keys($sources) : array_values(array_intersect(array_keys($sources), $parts));
        $created = [];

        // The chart first: the price list names the income accounts in it.
        $this->chart->forget();
        $this->chart->ensure();

        foreach ($wanted as $part) {
            [$rows] = $sources[$part];

            $created[$part] = DB::transaction(fn () => match ($part) {
                'departments' => $this->departments($rows),
                'services' => $this->services($rows),
                'rooms' => $this->rooms($rows),
                'wards' => $this->wards($rows),
                'lab_tests' => $this->labTests($rows),
                'radiology' => $this->radiology($rows),
                'stock' => $this->stockItems($practice, $rows, $by),
                'accounts' => 0,   // done above
                default => 0,
            });
        }

        return $created;
    }

    /** @return array<string, array{0:list<array<array-key,mixed>>,1:class-string<\Illuminate\Database\Eloquent\Model>|null}> */
    private function sources(Practice $practice): array
    {
        $t = $practice->template();

        $sources = [
            'departments' => [$t->departments(), Department::class],
            'services' => [$t->services(), Service::class],
            'rooms' => [$t->rooms(), Room::class],
        ];

        if ($practice->uses('inpatient') && $t->wards() !== []) {
            $sources['wards'] = [$t->wards(), Ward::class];
        }
        if ($practice->uses('laboratory') && $t->labTests() !== []) {
            $sources['lab_tests'] = [$t->labTests(), LabTest::class];
        }
        if ($practice->uses('radiology') && $t->radiologyStudies() !== []) {
            $sources['radiology'] = [$t->radiologyStudies(), RadiologyStudy::class];
        }
        if ($practice->uses('pharmacy') && $t->stockItems() !== []) {
            $sources['stock'] = [$t->stockItems(), StockItem::class];
        }

        $sources['accounts'] = [$t->accounts(), null];

        return $sources;
    }

    /** @param list<array<string,mixed>> $rows */
    private function departments(array $rows): int
    {
        return $this->each($rows, Department::class, function (array $r) {
            // Codes are unique too; one already used elsewhere is left off
            // rather than refused, since the name is what matters.
            $code = strtoupper((string) $r['code']);
            $taken = $code === '' || Department::withTrashed()->where('code', $code)->exists();

            Department::create(['name' => $r['name'], 'code' => $taken ? null : $code, 'is_active' => true]);
        });
    }

    /**
     * A service from a specialty pack is filed under that specialty and earns
     * into the specialty's own income account, so the income statement can
     * say what the dental chair brought in.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    private function services(array $rows): int
    {
        return $this->each($rows, Service::class, fn (array $r) => Service::create([
            'name' => $r['name'],
            'category' => $r['category'] ?? null,
            'ledger_account_id' => filled($r['income_key'] ?? null) && $this->chart->has($r['income_key'])
                ? $this->chart->key($r['income_key'])->id
                : null,
            'price' => (string) $r['price'],
            'tax_exempt' => (bool) ($r['tax_exempt'] ?? false),
            'is_active' => true,
        ]));
    }

    /** @param list<array<string,mixed>> $rows */
    private function rooms(array $rows): int
    {
        return $this->each($rows, Room::class, fn (array $r) => Room::create([
            'name' => $r['name'], 'type' => $r['type'], 'status' => RoomStatus::Available, 'capacity' => $r['capacity'],
        ]));
    }

    /** A ward with no beds cannot admit anyone, so it never comes without them. @param list<array<string,mixed>> $rows */
    private function wards(array $rows): int
    {
        return $this->each($rows, Ward::class, function (array $r) {
            $rate = (string) $r['daily_charge'];
            $ward = Ward::create(['name' => $r['name'], 'is_active' => true, 'default_daily_charge' => $rate]);

            for ($i = 1; $i <= (int) $r['beds']; $i++) {
                Bed::create([
                    'ward_id' => $ward->id, 'name' => 'Bed '.$i, 'daily_charge' => $rate,
                    'status' => BedStatus::Available, 'is_active' => true,
                ]);
            }
        });
    }

    /** @param list<array<string,mixed>> $rows */
    private function labTests(array $rows): int
    {
        return $this->each($rows, LabTest::class, fn (array $r) => LabTest::create([
            'name' => $r['name'], 'specimen' => $r['specimen'] ?: null, 'unit' => $r['unit'] ?: null,
            'reference_range' => $r['reference_range'] ?: null, 'price' => (string) $r['price'], 'is_active' => true,
        ]));
    }

    /** @param list<array<string,mixed>> $rows */
    private function radiology(array $rows): int
    {
        return $this->each($rows, RadiologyStudy::class, fn (array $r) => RadiologyStudy::create([
            'name' => $r['name'], 'modality' => $r['modality'], 'body_part' => $r['body_part'],
            'price' => (string) $r['price'], 'is_active' => true,
        ]));
    }

    /**
     * Each item onto the shelf with its opening count, through the stock
     * ledger as OPENING stock — the hospital owned it before today, so it is
     * the hospital's equity in the books, not a debt to a supplier.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    private function stockItems(Practice $practice, array $rows, ?int $by): int
    {
        $units = [];
        foreach ($practice->template()->stockCategories() as $c) {
            $units[mb_strtolower($c['name'])] = $c['unit'];
        }

        return $this->each($rows, StockItem::class, function (array $r) use ($units, $by) {
            $category = StockCategory::firstOrCreate(
                ['name' => $r['category']],
                ['unit' => $units[mb_strtolower($r['category'])] ?? $r['unit'], 'is_active' => true],
            );

            $item = StockItem::create([
                'uuid' => (string) Str::uuid(),
                'stock_category_id' => $category->id,
                'name' => $r['name'],
                'unit' => $r['unit'],
                'original_quantity' => 0,
                'current_quantity' => 0,
                'cost_price' => HospitalSettings::decimal((string) $r['cost_price'], 2),
                'sale_price' => HospitalSettings::decimal((string) $r['sale_price'], 2),
                'current_stock_value' => 0,
                'reorder_level' => $r['reorder_level'],
                'is_active' => true,
            ]);

            $opening = HospitalSettings::decimal((string) $r['quantity'], 2);
            if (bccomp($opening, '0', 2) > 0) {
                $this->stock->adjust($item, StockMovementReason::OpeningStock, $opening, $by, 'Opening stock');
            }
        });
    }

    /**
     * Every name the hospital has used for one of these — including rows it
     * deleted, because the unique index on the name still counts them.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return list<string>
     */
    private function names(string $model): array
    {
        $query = $model::query();
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);
        }

        return $query->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();
    }

    /**
     * Create every row the hospital does not have by name yet.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function each(array $rows, string $model, \Closure $create): int
    {
        $have = $this->names($model);
        $created = 0;

        foreach ($rows as $row) {
            if (in_array(mb_strtolower($row['name']), $have, true)) {
                continue;
            }

            $create($row);
            $have[] = mb_strtolower($row['name']);
            $created++;
        }

        return $created;
    }
}
