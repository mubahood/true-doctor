<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\InvoiceCreditService;
use App\Support\CurrentHospital;
use Illuminate\Support\Facades\DB;

/**
 * Refunds and credit notes in the demo hospital (docs/refunds.md).
 *
 * Built from the demo's own invoices, so every reason names a line that is
 * really on that patient's bill:
 *
 *   - a paid bill with a line that was not done: the front desk asks for a
 *     credit note, accounts approves it and refunds the cash;
 *   - a bill still being paid with a line at the wrong price: accounts credits
 *     the difference;
 *   - another paid bill where the front desk has asked for a credit note
 *     that is still waiting for approval.
 *
 * Idempotent: a hospital that already has any credit note or refund is left
 * alone, so running it twice changes nothing.
 */
class Refunds
{
    public static function run(Hospital $hospital): void
    {
        app(CurrentHospital::class)->within($hospital->id, function () use ($hospital) {
            if (InvoiceCredit::query()->exists()) {
                return;
            }

            $accounts = self::staff($hospital, ['accountant', 'hospital_admin']);
            $desk = self::staff($hospital, ['receptionist']) ?? $accounts;
            if ($accounts === null || $desk === null) {
                return;
            }

            $service = app(InvoiceCreditService::class);

            $paid = Invoice::query()->where('status', InvoiceStatus::Paid->value)
                ->where('credited', 0)->has('items', '>=', 2)->orderByDesc('id')->limit(2)->get();
            $open = Invoice::query()->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
                ->where('credited', 0)->has('items')->orderByDesc('id')->first();

            DB::transaction(function () use ($service, $paid, $open, $accounts, $desk) {
                // 1. Not done, so credited and the cash handed back.
                if ($first = $paid->get(0)) {
                    $line = self::line($first, last: true);
                    $amount = self::amount($service, $first, $line);
                    $credit = self::some($amount) ? $service->requestCreditNote($first, $amount, $line->description.' was not done. Charged in error.', $desk) : null;
                    if ($credit?->isPending()) {
                        $service->approve($credit, $accounts);
                    }
                    $back = $service->figures($first->fresh())['can_refund'];
                    if (bccomp($back, '0', 2) > 0) {
                        $service->requestRefund($first->fresh(), PaymentMethod::Cash, $back, 'Cash returned for '.$line->description.'.', $accounts);
                    }
                }

                // 2. The wrong price on a bill still open: accounts credits the difference.
                if ($open !== null) {
                    $line = self::line($open, last: false);
                    $amount = self::amount($service, $open, $line, half: true);
                    if (self::some($amount)) {
                        $service->requestCreditNote($open, $amount, $line->description.' was billed at the wrong price.', $accounts);
                    }
                }

                // 3. Asked for at the desk, waiting for accounts.
                if ($second = $paid->get(1)) {
                    $line = self::line($second, last: true);
                    $amount = self::amount($service, $second, $line);
                    if (self::some($amount)) {
                        $service->requestCreditNote($second, $amount, 'Patient left before the '.mb_strtolower($line->description).'.', $desk);
                    }
                }
            });
        });
    }

    /** @param list<string> $roles */
    private static function staff(Hospital $hospital, array $roles): ?User
    {
        foreach ($roles as $role) {
            $user = User::query()->where('hospital_id', $hospital->id)->where('role', $role)->orderBy('id')->first();
            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    private static function line(Invoice $invoice, bool $last): InvoiceItem
    {
        $q = $invoice->items()->where('line_total', '>', 0);

        return ($last ? $q->orderByDesc('id') : $q->orderBy('id'))->firstOrFail();
    }

    /** The line's amount (or half of it), never more than can be credited. */
    private static function amount(InvoiceCreditService $service, Invoice $invoice, InvoiceItem $line, bool $half = false): string
    {
        $want = $half ? bcdiv((string) $line->line_total, '2', 2) : (string) $line->line_total;
        $room = $service->figures($invoice->fresh())['creditable'];

        return bccomp($want, $room, 2) > 0 ? $room : $want;
    }

    private static function some(string $amount): bool
    {
        return bccomp($amount, '0', 2) > 0;
    }
}
