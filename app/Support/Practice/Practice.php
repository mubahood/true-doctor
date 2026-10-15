<?php

namespace App\Support\Practice;

use App\Models\Hospital;
use App\Support\HospitalSettings;

/**
 * What kind of practice a hospital is, and what follows from that
 * (docs/practice.md).
 *
 * Stored in `settings.practice` as the sector, the packs the hospital added on
 * top of it, and the modules it uses. Everything else is derived: the starter
 * data every setup step and every module page suggests (`template()`), and
 * which parts of the system its menu shows (`uses()`).
 *
 * A hospital that has not chosen yet reads as a general hospital with every
 * module on — which is exactly what every hospital was before there was a
 * choice — so nothing that asks this can be left without an answer.
 */
final class Practice
{
    /** Parts of the system a practice can do without. Everything else is always on. */
    public const MODULES = [
        'inpatient' => ['label' => 'Inpatient care', 'icon' => 'fa-bed', 'hint' => 'Wards, beds, admissions and nightly charges.'],
        'laboratory' => ['label' => 'Laboratory', 'icon' => 'fa-flask', 'hint' => 'Lab orders, the bench worklist and results.'],
        'radiology' => ['label' => 'Imaging', 'icon' => 'fa-x-ray', 'hint' => 'Imaging orders, studies and reports.'],
        'pharmacy' => ['label' => 'Pharmacy & stock', 'icon' => 'fa-pills', 'hint' => 'Stock items, dispensing and the stock ledger.'],
        'insurance' => ['label' => 'Insurance', 'icon' => 'fa-shield-heart', 'hint' => 'Insurers, claims and insurer floats.'],
        'cards' => ['label' => 'Prepaid cards', 'icon' => 'fa-wallet', 'hint' => 'Patient and family cards with a balance.'],
    ];

    /** @var array<string, class-string<Pack>> */
    public const PACKS = [
        'general' => Packs\General::class,
        'emergency' => Packs\Emergency::class,
        'inpatient' => Packs\Inpatient::class,
        'maternity' => Packs\Maternity::class,
        'paediatrics' => Packs\Paediatrics::class,
        'surgery' => Packs\Surgery::class,
        'chronic' => Packs\ChronicCare::class,
        'laboratory' => Packs\Laboratory::class,
        'imaging' => Packs\Imaging::class,
        'pharmacy' => Packs\Pharmacy::class,
        'dental' => Packs\Dental::class,
        'eye' => Packs\Eye::class,
        'ent' => Packs\Ent::class,
        'physiotherapy' => Packs\Physiotherapy::class,
        'mental_health' => Packs\MentalHealth::class,
        'dermatology' => Packs\Dermatology::class,
    ];

    public const DEFAULT_SECTOR = 'general_hospital';

    /**
     * @param  list<string>  $extraPacks  packs added on top of the sector's own
     * @param  list<string>|null  $modules  null: the sector's defaults
     */
    public function __construct(
        public readonly Sector $sector,
        public readonly array $extraPacks = [],
        public readonly ?array $modules = null,
        public readonly bool $chosen = false,
        private readonly string $currency = 'UGX',
    ) {}

    /** @return array<string, Sector> in the order they are offered */
    public static function sectors(): array
    {
        $all = [
            new Sector('general_hospital', 'General hospital', 'fa-hospital',
                'Outpatients and inpatients, theatre, maternity, a laboratory, imaging and a pharmacy under one roof.',
                ['general', 'emergency', 'inpatient', 'maternity', 'paediatrics', 'surgery', 'chronic', 'laboratory', 'imaging', 'pharmacy', 'dental', 'eye', 'ent', 'physiotherapy'],
                ['mental_health', 'dermatology'], ['insurance', 'cards']),
            new Sector('medical_clinic', 'Medical clinic / health centre', 'fa-house-medical',
                'General practice: consultations, a small laboratory, a dispensary and chronic-care reviews.',
                ['general', 'chronic', 'paediatrics', 'laboratory', 'pharmacy'],
                ['maternity', 'dental', 'imaging', 'inpatient', 'emergency'], ['insurance', 'cards']),
            new Sector('dental_clinic', 'Dental clinic', 'fa-tooth',
                'Chairs, fillings, extractions, root canals, crowns, dentures, braces and dental materials.',
                ['dental'],
                ['pharmacy', 'imaging'], ['insurance', 'cards']),
            new Sector('eye_clinic', 'Eye clinic & optical', 'fa-eye',
                'Eye tests, refraction, eye surgery, and an optical shop selling frames and lenses.',
                ['eye'],
                ['pharmacy', 'ent'], ['insurance', 'cards']),
            new Sector('maternity_centre', 'Maternity & women\'s health', 'fa-person-pregnant',
                'Antenatal care, deliveries, family planning, gynaecology and newborn care.',
                ['maternity', 'paediatrics', 'laboratory', 'pharmacy'],
                ['surgery', 'imaging', 'inpatient'], ['radiology', 'insurance', 'cards']),
            new Sector('childrens_clinic', 'Children\'s clinic', 'fa-baby',
                'Sick-child visits, immunisation, growth monitoring and a children\'s dispensary.',
                ['paediatrics', 'general', 'laboratory', 'pharmacy'],
                ['inpatient', 'emergency'], ['insurance', 'cards']),
            new Sector('diagnostic_lab', 'Diagnostic laboratory', 'fa-flask',
                'A laboratory taking walk-ins and referrals: sample collection, the bench and results.',
                ['laboratory'],
                ['imaging'], ['insurance']),
            new Sector('imaging_centre', 'Imaging centre', 'fa-x-ray',
                'X-ray, ultrasound, CT and MRI, with reporting for referring doctors.',
                ['imaging'],
                ['laboratory'], ['insurance']),
            new Sector('physio_centre', 'Physiotherapy & rehabilitation', 'fa-person-walking',
                'Assessments and courses of therapy, home visits and mobility aids.',
                ['physiotherapy'],
                ['general'], ['pharmacy', 'insurance', 'cards']),
            new Sector('mental_health_centre', 'Mental health & counselling', 'fa-brain',
                'Psychiatry, counselling and therapy, addiction care and rehabilitation.',
                ['mental_health'],
                ['inpatient', 'laboratory'], ['insurance', 'cards']),
            new Sector('skin_clinic', 'Skin & aesthetics clinic', 'fa-hand-dots',
                'Dermatology, skin procedures and aesthetic treatments, with skin-care products.',
                ['dermatology'],
                ['laboratory'], ['cards']),
            new Sector('ent_clinic', 'Ear, nose & throat clinic', 'fa-ear-listen',
                'ENT consultations, hearing tests, ear care and ENT surgery.',
                ['ent'],
                ['surgery', 'pharmacy', 'imaging'], ['insurance', 'cards']),
        ];

        $out = [];
        foreach ($all as $sector) {
            $out[$sector->key] = $sector;
        }

        return $out;
    }

    public static function sector(string $key): ?Sector
    {
        return self::sectors()[$key] ?? null;
    }

    /** @return class-string<Pack>|null */
    public static function pack(string $key): ?string
    {
        return self::PACKS[$key] ?? null;
    }

    /** The practice of the hospital in context (a general hospital where there is none). */
    public static function current(): self
    {
        return self::of(app(HospitalSettings::class)->hospital());
    }

    public static function of(?Hospital $hospital): self
    {
        $stored = is_array($hospital?->settings['practice'] ?? null) ? $hospital->settings['practice'] : [];
        $sector = self::sector((string) ($stored['sector'] ?? '')) ?? self::sectors()[self::DEFAULT_SECTOR];

        $currency = is_array($hospital?->settings['billing'] ?? null)
            ? (string) ($hospital->settings['billing']['currency_code'] ?? $hospital->currency ?? 'UGX')
            : (string) ($hospital->currency ?? 'UGX');

        return new self(
            $sector,
            self::validPacks(is_array($stored['packs'] ?? null) ? $stored['packs'] : []),
            is_array($stored['modules'] ?? null) ? self::validModules($stored['modules']) : null,
            filled($stored['sector'] ?? null) && self::sector((string) $stored['sector']) !== null,
            $currency ?: 'UGX',
        );
    }

    /** The sector's packs, then the ones the hospital added — the order suggestions are listed in. @return list<string> */
    public function packKeys(): array
    {
        return array_values(array_unique([...$this->sector->packs, ...$this->extraPacks]));
    }

    /** Packs a hospital of this kind could add, the commonly added first. @return list<string> */
    public function addablePacks(): array
    {
        $own = $this->sector->packs;

        return array_values(array_diff(
            array_unique([...$this->sector->suggested, ...array_keys(self::PACKS)]),
            $own,
        ));
    }

    /** @return list<string> */
    public function modules(): array
    {
        return $this->modules ?? self::defaultModules($this->sector, $this->extraPacks);
    }

    /** What a sector and its packs switch on, before anybody changes it. @param list<string> $extraPacks @return list<string> */
    public static function defaultModules(Sector $sector, array $extraPacks = []): array
    {
        $on = $sector->modules;

        foreach ([...$sector->packs, ...$extraPacks] as $key) {
            $pack = self::pack($key);
            if ($pack !== null) {
                $on = [...$on, ...$pack::modules()];
            }
        }

        // In the order the system lists them, not the order they were found.
        return array_values(array_intersect(array_keys(self::MODULES), $on));
    }

    /**
     * Which module a permission belongs to, by its prefix. A permission that
     * belongs to none is never switched off by the practice.
     */
    private const PERMISSION_MODULES = [
        'ipd.' => 'inpatient', 'wards.' => 'inpatient',
        'lab.' => 'laboratory',
        'radiology.' => 'radiology',
        'pharmacy.' => 'pharmacy',
        'insurance.' => 'insurance',
        'patients.card.' => 'cards',
    ];

    /**
     * Whether this practice does the work a permission is for — so a dental
     * clinic's dashboard does not count beds it does not have. The menu hides
     * the same modules (Navigation's `module` key).
     */
    public function allows(string $permission): bool
    {
        foreach (self::PERMISSION_MODULES as $prefix => $module) {
            if (str_starts_with($permission, $prefix)) {
                return $this->uses($module);
            }
        }

        return true;
    }

    public function uses(string $module): bool
    {
        // Something that is not a switchable module is always on.
        return ! array_key_exists($module, self::MODULES) || in_array($module, $this->modules(), true);
    }

    public function template(): Template
    {
        return new Template(
            array_values(array_filter(array_map(fn (string $k) => self::pack($k), $this->packKeys()))),
            $this->currency,
        );
    }

    /** @param list<mixed> $packs @return list<string> */
    public static function validPacks(array $packs): array
    {
        return array_values(array_unique(array_filter($packs, fn ($p) => is_string($p) && isset(self::PACKS[$p]))));
    }

    /** @param list<mixed> $modules @return list<string> */
    public static function validModules(array $modules): array
    {
        return array_values(array_intersect(array_keys(self::MODULES), $modules));
    }
}
