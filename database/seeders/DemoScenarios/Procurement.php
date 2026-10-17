<?php

namespace Database\Seeders\DemoScenarios;

use App\Models\Hospital;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProcurementService;
use App\Support\CurrentHospital;
use Database\Seeders\DemoMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Procurement in the demo hospital (roadmap 04 §7): two suppliers and the
 * store's last six weeks of buying, made through ProcurementService so the
 * shelf, the stock ledger and the books all agree.
 *
 *   - a delivered order with batches and expiries, ten packs sent back damaged;
 *   - an order part delivered last week, the rest still to come;
 *   - an order sent and expected next week;
 *   - a draft the pharmacist is still putting together.
 *
 * Idempotent: if the first supplier already exists, nothing is added again.
 */
final class Procurement
{
    private const FIRST_SUPPLIER = 'Nile Pharma Distributors';

    public static function run(Hospital $hospital): void
    {
        app(CurrentHospital::class)->within($hospital->id, fn () => self::seed($hospital));
    }

    private static function seed(Hospital $hospital): void
    {
        if (Supplier::query()->where('name', self::FIRST_SUPPLIER)->exists()) {
            return;
        }

        $buyer = User::query()->where('hospital_id', $hospital->id)->where('role', 'pharmacist')->orderBy('id')->first()
            ?? User::query()->where('hospital_id', $hospital->id)->where('role', 'hospital_admin')->orderBy('id')->first();
        $items = StockItem::query()->where('is_active', true)->orderBy('id')->limit(4)->get();

        if ($buyer === null || $items->count() < 2) {
            return;
        }

        $money = fn (int $shillings) => DemoMoney::in($hospital->currency, $shillings);
        $procurement = app(ProcurementService::class);
        $today = Carbon::now()->startOfDay();

        DB::transaction(function () use ($hospital, $buyer, $items, $money, $procurement, $today) {
            $nile = Supplier::create([
                'hospital_id' => $hospital->id,
                'name' => self::FIRST_SUPPLIER,
                'contact_person' => 'Grace Achieng',
                'phone' => '+256 772 410 118',
                'email' => 'orders@nilepharma.test',
                'address' => 'Plot 22, Industrial Area, Kampala',
                'tin' => '1000234561',
                'payment_terms_days' => 30,
                'is_active' => true,
            ]);
            $kmsl = Supplier::create([
                'hospital_id' => $hospital->id,
                'name' => 'Kampala Medical Supplies',
                'contact_person' => 'Ronald Ssali',
                'phone' => '+256 701 552 903',
                'email' => 'sales@kampalamedical.test',
                'address' => 'Nasser Road, Kampala',
                'payment_terms_days' => 14,
                'is_active' => true,
            ]);

            [$first, $second] = [$items[0], $items[1]];
            $third = $items[2] ?? $second;

            // Six weeks ago: ordered, delivered complete a few days later.
            $done = self::at($today->copy()->subDays(42)->setTime(10, 0), fn () => $procurement->createOrder($nile, [
                'expected_date' => $today->copy()->subDays(38)->toDateString(),
                'notes' => 'Monthly restock.',
            ], [
                ['stock_item_id' => $first->id, 'quantity' => '500', 'unit_cost' => $money(95)],
                ['stock_item_id' => $second->id, 'quantity' => '300', 'unit_cost' => $money(280)],
            ], $buyer));
            self::at($today->copy()->subDays(42)->setTime(10, 20), fn () => $procurement->markOrdered($done, $buyer));
            $lines = $done->items()->get();
            $grn = self::at($today->copy()->subDays(39)->setTime(14, 30), fn () => $procurement->receive($done, [
                'received_on' => $today->copy()->subDays(39)->toDateString(),
                'supplier_invoice_no' => 'NPD-INV-8841',
            ], [
                ['purchase_order_item_id' => $lines[0]->id, 'quantity' => '500', 'batch_no' => 'PCM2408A', 'expiry_date' => $today->copy()->addMonths(20)->toDateString()],
                ['purchase_order_item_id' => $lines[1]->id, 'quantity' => '300', 'batch_no' => 'AMX2407C', 'expiry_date' => $today->copy()->addMonths(14)->toDateString()],
            ], $buyer));
            // Ten packs arrived crushed and went back with the driver's next run.
            $crushed = $grn->items->firstWhere('stock_item_id', $second->id);
            if ($crushed !== null) {
                self::at($today->copy()->subDays(37)->setTime(9, 15), fn () => $procurement->returnToSupplier($crushed, '10', 'Damaged in transit, packs crushed', $buyer));
            }

            // Last week: part delivered, the rest promised.
            $part = self::at($today->copy()->subDays(9)->setTime(11, 0), fn () => $procurement->createOrder($kmsl, [
                'expected_date' => $today->copy()->subDays(5)->toDateString(),
            ], [
                ['stock_item_id' => $third->id, 'quantity' => '200', 'unit_cost' => $money(150)],
                ['stock_item_id' => $first->id, 'quantity' => '400', 'unit_cost' => $money(98)],
            ], $buyer));
            self::at($today->copy()->subDays(9)->setTime(11, 10), fn () => $procurement->markOrdered($part, $buyer));
            $partLines = $part->items()->get();
            self::at($today->copy()->subDays(5)->setTime(15, 0), fn () => $procurement->receive($part, [
                'received_on' => $today->copy()->subDays(5)->toDateString(),
                'supplier_invoice_no' => 'KMS-30217',
            ], [
                ['purchase_order_item_id' => $partLines[0]->id, 'quantity' => '200', 'batch_no' => 'KM-5512', 'expiry_date' => $today->copy()->addMonths(18)->toDateString()],
                ['purchase_order_item_id' => $partLines[1]->id, 'quantity' => '250', 'batch_no' => 'PCM2409B', 'expiry_date' => $today->copy()->addMonths(22)->toDateString()],
            ], $buyer));

            // Sent yesterday, due next week.
            $sent = self::at($today->copy()->subDay()->setTime(9, 30), fn () => $procurement->createOrder($nile, [
                'expected_date' => $today->copy()->addDays(6)->toDateString(),
            ], [
                ['stock_item_id' => $second->id, 'quantity' => '200', 'unit_cost' => $money(280)],
            ], $buyer));
            self::at($today->copy()->subDay()->setTime(9, 40), fn () => $procurement->markOrdered($sent, $buyer));

            // Still being put together.
            self::at($today->copy()->setTime(8, 45), fn () => $procurement->createOrder($kmsl, [
                'notes' => 'Check prices before sending.',
            ], [
                ['stock_item_id' => $first->id, 'quantity' => '300', 'unit_cost' => $money(97)],
            ], $buyer));
        });
    }

    /**
     * Run $work as though the clock read $when.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    private static function at(Carbon $when, \Closure $work): mixed
    {
        $was = Carbon::hasTestNow() ? Carbon::now() : null;
        Carbon::setTestNow($when);

        try {
            return $work();
        } finally {
            Carbon::setTestNow($was);
        }
    }
}
