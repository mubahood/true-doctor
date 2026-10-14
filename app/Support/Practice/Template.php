<?php

namespace App\Support\Practice;

/**
 * A hospital's starter data: its packs merged, in order, and priced in its
 * own currency.
 *
 * Merging is by name, first one wins — so a practice whose own specialty is
 * listed first keeps its own price for a service another pack also offers.
 * The lists are ordered the way a person reads them: a dental practice's visit
 * reasons start with "Toothache", not "Fever".
 *
 * The shapes returned are exactly the ones App\Support\SampleCatalogue has
 * always returned, so every importer that read it keeps working.
 */
final class Template
{
    /**
     * Typing aids every practice needs whatever it does. The first two are
     * why most people come anywhere, so they lead every list; the practice's
     * own reasons follow straight after, ahead of the rest.
     */
    private const LEADING_REASONS = ['New complaint', 'Follow-up visit'];

    private const CORE_REASONS = ['Review test results', 'Routine check-up', 'Referred from another facility', 'Emergency'];

    private const CORE_REMARKS = ['Review in three days', 'Review if no improvement', 'Continue current medication', 'Refer to a specialist', 'Await laboratory results'];

    /** @var array<string, list<string>> */
    private const CORE_ORDER_TITLES = [
        'consultation' => ['Doctor review', 'Specialist review', 'Follow-up review', 'Review results with the doctor', 'Second opinion'],
        'procedure' => [],
        'pharmacy' => ['Dispense prescription', 'Take-home medicines', 'Refill', 'Single dose now'],
        'admission' => [],
    ];

    /**
     * @param  list<class-string<Pack>>  $packs
     */
    public function __construct(
        public readonly array $packs,
        private readonly string $currency = 'UGX',
    ) {}

    /** @return list<string> */
    public function packKeys(): array
    {
        return array_map(fn (string $pack) => $pack::key(), $this->packs);
    }

    // ── Catalogues ───────────────────────────────────────────────────────

    /** @return list<array{name:string,code:string}> */
    public function departments(): array
    {
        return $this->merge('departments', fn (array $r) => ['name' => $r[0], 'code' => $r[1]]);
    }

    /**
     * `category` is the pack's own label, so a price list reads by specialty;
     * `income_key` is the income account the pack's services earn into.
     *
     * @return list<array{name:string,price:float,tax_exempt:bool,category:string,income_key:?string}>
     */
    public function services(): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->packs as $pack) {
            foreach ($pack::services() as [$name, $price]) {
                if ($this->claim($seen, $name)) {
                    $rows[] = [
                        'name' => $name,
                        'price' => $this->price($price),
                        'tax_exempt' => false,
                        'category' => $pack::label(),
                        'income_key' => $pack::incomeKey(),
                    ];
                }
            }
        }

        return $rows;
    }

    /** @return list<array{name:string,specimen:string,unit:string,reference_range:string,price:float}> */
    public function labTests(): array
    {
        return $this->merge('labTests', fn (array $r) => [
            'name' => $r[0], 'specimen' => $r[1], 'unit' => $r[2], 'reference_range' => $r[3], 'price' => $this->price($r[4]),
        ]);
    }

    /** @return list<array{name:string,modality:string,body_part:string,price:float}> */
    public function radiologyStudies(): array
    {
        return $this->merge('radiologyStudies', fn (array $r) => [
            'name' => $r[0], 'modality' => $r[1], 'body_part' => $r[2], 'price' => $this->price($r[3]),
        ]);
    }

    /**
     * Every category a pack names, AND every category one of its items is
     * filed under — a dental practice's chlorhexidine is a syrup whether or
     * not the pharmacy pack is there to say so.
     *
     * @return list<array{name:string,unit:string}>
     */
    public function stockCategories(): array
    {
        $rows = $this->merge('stockCategories', fn (array $r) => ['name' => $r[0], 'unit' => $r[1]]);
        $seen = array_map(fn (array $r) => mb_strtolower($r['name']), $rows);

        foreach ($this->stockItems() as $item) {
            if (! in_array(mb_strtolower($item['category']), $seen, true)) {
                $seen[] = mb_strtolower($item['category']);
                $rows[] = ['name' => $item['category'], 'unit' => $item['unit']];
            }
        }

        return $rows;
    }

    /** @return list<array{name:string,category:string,unit:string,quantity:float,cost_price:float,sale_price:float,reorder_level:float}> */
    public function stockItems(): array
    {
        return $this->merge('stockItems', fn (array $r) => [
            'name' => $r[0], 'category' => $r[1], 'unit' => $r[2],
            'quantity' => (float) $r[3], 'cost_price' => $this->price($r[4]),
            'sale_price' => $this->price($r[5]), 'reorder_level' => (float) $r[6],
        ]);
    }

    /** @return list<array{name:string,type:string,capacity:int}> */
    public function rooms(): array
    {
        return $this->merge('rooms', fn (array $r) => ['name' => $r[0], 'type' => $r[1], 'capacity' => $r[2]]);
    }

    /** @return list<array{name:string,beds:int,daily_charge:float}> */
    public function wards(): array
    {
        return $this->merge('wards', fn (array $r) => ['name' => $r[0], 'beds' => $r[1], 'daily_charge' => $this->price($r[2])]);
    }

    /**
     * The accounts these packs add to the chart.
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:?string}>
     */
    public function accounts(): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->packs as $pack) {
            foreach ($pack::accounts() as $row) {
                if ($this->claim($seen, $row[0])) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    // ── Typing aids ──────────────────────────────────────────────────────

    /** @return list<string> */
    public function visitReasons(): array
    {
        return $this->phrases('visitReasons', self::CORE_REASONS, self::LEADING_REASONS);
    }

    /** @return array{complaints:list<string>,diagnosis:list<string>,doctor_remarks:list<string>} */
    public function clinicalPhrases(): array
    {
        return [
            'complaints' => $this->phrases('complaints'),
            'diagnosis' => $this->phrases('diagnoses'),
            'doctor_remarks' => $this->phrases('remarks', self::CORE_REMARKS),
        ];
    }

    /** @return array<string, list<string>> */
    public function orderTitles(): array
    {
        $titles = [];

        foreach (self::CORE_ORDER_TITLES as $type => $core) {
            $seen = [];
            $titles[$type] = [];

            foreach ([$core, ...array_map(fn (string $p) => $p::orderTitles()[$type] ?? [], $this->packs)] as $list) {
                foreach ($list as $title) {
                    if ($this->claim($seen, $title)) {
                        $titles[$type][] = $title;
                    }
                }
            }
        }

        return $titles;
    }

    /**
     * How much there is of each, for the screen where a hospital is deciding.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        return [
            'departments' => count($this->departments()),
            'services' => count($this->services()),
            'rooms' => count($this->rooms()),
            'wards' => count($this->wards()),
            'lab_tests' => count($this->labTests()),
            'radiology' => count($this->radiologyStudies()),
            'stock_items' => count($this->stockItems()),
        ];
    }

    // ── Money ────────────────────────────────────────────────────────────

    /**
     * A UGX price in this hospital's currency, rounded the way a price list is
     * written (two significant figures — 42,000 not 42,105).
     *
     * The rates are deliberately rough and deliberately here: these are
     * starting points a hospital edits before it saves them, and "a
     * consultation costs about this much" is all they need to say. Without
     * them a Nairobi clinic was offered a consultation at KSh 20,000.
     */
    public function price(int|float $ugx): float
    {
        $value = $ugx * (PriceScale::RATES[strtoupper($this->currency)] ?? 1.0);

        return PriceScale::round($value);
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    /**
     * @template T of array
     *
     * @param  \Closure(array): T  $shape
     * @return list<T>
     */
    private function merge(string $list, \Closure $shape): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->packs as $pack) {
            foreach ($pack::$list() as $row) {
                if ($this->claim($seen, $row[0])) {
                    $rows[] = $shape($row);
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $tail
     * @param  list<string>  $head
     * @return list<string>
     */
    private function phrases(string $list, array $tail = [], array $head = []): array
    {
        $out = [];
        $seen = [];

        foreach ([$head, ...array_map(fn (string $p) => $p::$list(), $this->packs), $tail] as $phrases) {
            foreach ($phrases as $phrase) {
                if ($this->claim($seen, $phrase)) {
                    $out[] = $phrase;
                }
            }
        }

        return $out;
    }

    /** First one wins: true the first time a name is seen. @param array<string,true> $seen */
    private function claim(array &$seen, string $name): bool
    {
        $key = mb_strtolower(trim($name));

        if (isset($seen[$key])) {
            return false;
        }

        $seen[$key] = true;

        return true;
    }
}
