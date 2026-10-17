<?php

namespace App\Services;

use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\PatientCard;
use App\Models\User;
use App\Services\Finance\Bookkeeper;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use App\Support\Sequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Credit notes and refunds (roadmap 04 §8, docs/refunds.md).
 *
 * A **credit note** takes money off what an invoice asks for: a test that was
 * not done, a line charged twice. A **refund** pays money back out: only what
 * the patient paid beyond what the invoice now asks for, so a refund always
 * follows a credit note.
 *
 * Both are asked for with a reason and decided by someone holding
 * `billing.refund`; when that person asks, it is approved at once. Approval
 * is the money moment, in one transaction under a lock on the invoice: the
 * limits are checked again, the document is numbered, the invoice's running
 * totals and balance move, a refund to a card credits the card, and the
 * books get their entry. Nothing is edited afterwards.
 */
class InvoiceCreditService
{
    /** How a refund can go back: money in hand, or onto the patient's card. */
    public const REFUND_METHODS = ['cash', 'mobile_money', 'bank', 'card'];

    public function __construct(
        private readonly Bookkeeper $bookkeeper,
        private readonly CardService $cards,
    ) {}

    /**
     * What can still be credited and refunded on an invoice, counting the
     * requests already waiting.
     *
     * @return array{total:string,credited:string,refunded:string,net:string,held:string,balance:string,refundable:string,creditable:string,pending_credit:string,pending_refund:string,can_refund:string}
     */
    public function figures(Invoice $invoice): array
    {
        $pending = InvoiceCredit::query()->where('invoice_id', $invoice->id)
            ->where('status', CreditStatus::Pending->value)->get(['kind', 'amount']);

        $pendingCredit = '0.00';
        $pendingRefund = '0.00';
        foreach ($pending as $p) {
            if ($p->kind === CreditKind::CreditNote) {
                $pendingCredit = bcadd($pendingCredit, (string) $p->amount, 2);
            } else {
                $pendingRefund = bcadd($pendingRefund, (string) $p->amount, 2);
            }
        }

        $net = $invoice->netTotal();
        $creditable = $this->floor(bcsub($net, $pendingCredit, 2));

        // A refund may be asked for on the strength of a credit note still
        // waiting; approval checks it again against what is real by then.
        $projected = $this->floor(bcsub($invoice->held(), bcsub($net, $pendingCredit, 2), 2));

        return [
            'total' => (string) $invoice->total,
            'credited' => (string) $invoice->credited,
            'refunded' => (string) $invoice->refunded,
            'net' => $net,
            'held' => $invoice->held(),
            'balance' => (string) $invoice->balance,
            'refundable' => $invoice->refundable(),
            'creditable' => $creditable,
            'pending_credit' => $pendingCredit,
            'pending_refund' => $pendingRefund,
            'can_refund' => $this->floor(bcsub($projected, $pendingRefund, 2)),
        ];
    }

    /** Ask for a credit note; approved at once when the asker may approve. */
    public function requestCreditNote(Invoice $invoice, string $amount, string $reason, User $by): InvoiceCredit
    {
        return $this->request($invoice, CreditKind::CreditNote, $amount, $reason, $by);
    }

    /** Ask for a refund; approved at once when the asker may approve. */
    public function requestRefund(Invoice $invoice, PaymentMethod $method, string $amount, string $reason, User $by, ?string $reference = null, ?PatientCard $card = null): InvoiceCredit
    {
        if (! in_array($method->value, self::REFUND_METHODS, true)) {
            throw new RuntimeException('A refund goes back as cash, mobile money, a bank transfer or onto the patient\'s card.');
        }
        if ($method === PaymentMethod::Card && ($card === null || ! $card->covers((int) $invoice->patient_id))) {
            throw new RuntimeException('Choose a card this patient is on.');
        }
        if ($method->needsReference() && trim((string) $reference) === '') {
            throw new RuntimeException('A reference is what proves this refund later.');
        }

        return $this->request($invoice, CreditKind::Refund, $amount, $reason, $by, [
            'method' => $method,
            'reference' => trim((string) $reference) ?: null,
            'patient_card_id' => $method === PaymentMethod::Card ? $card->id : null,
        ]);
    }

    public function approve(InvoiceCredit $credit, User $by): InvoiceCredit
    {
        $this->assertMayApprove($by);

        return $this->within($credit->hospital_id, fn () => DB::transaction(function () use ($credit, $by) {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($credit->invoice_id)->lockForUpdate()->firstOrFail();
            /** @var InvoiceCredit $locked */
            $locked = InvoiceCredit::query()->whereKey($credit->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new RuntimeException('This has already been '.strtolower($locked->status->label()).'.');
            }

            return $this->approveLocked($locked, $invoice, $by);
        }));
    }

    public function reject(InvoiceCredit $credit, string $note, User $by): InvoiceCredit
    {
        $this->assertMayApprove($by);
        $note = trim($note);
        if (mb_strlen($note) < 3) {
            throw new RuntimeException('Say why it is rejected.');
        }

        return $this->within($credit->hospital_id, fn () => DB::transaction(function () use ($credit, $note, $by) {
            /** @var InvoiceCredit $locked */
            $locked = InvoiceCredit::query()->whereKey($credit->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new RuntimeException('This has already been '.strtolower($locked->status->label()).'.');
            }

            $locked->update([
                'status' => CreditStatus::Rejected,
                'decided_by' => $by->id,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ]);

            return $locked;
        }));
    }

    // ── Inside ───────────────────────────────────────────────────────────

    /** @param array<string,mixed> $extra */
    private function request(Invoice $invoice, CreditKind $kind, string $amount, string $reason, User $by, array $extra = []): InvoiceCredit
    {
        $amount = HospitalSettings::decimal($amount, 2);
        $reason = trim($reason);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException('The amount must be more than nothing.');
        }
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Give the reason: it is printed on the document and kept in the books.');
        }

        return $this->within($invoice->hospital_id, fn () => DB::transaction(function () use ($invoice, $kind, $amount, $reason, $by, $extra) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertOpenToCredit($locked);

            $f = $this->figures($locked);
            $room = $kind === CreditKind::CreditNote ? $f['creditable'] : $f['can_refund'];
            if (bccomp($amount, $room, 2) > 0) {
                throw new RuntimeException($kind === CreditKind::CreditNote
                    ? 'At most '.HospitalSettings::money($room).' can be credited on this invoice.'
                    : (bccomp($room, '0', 2) === 0
                        ? 'Nothing has been paid beyond what this invoice asks for. Credit it first, then refund.'
                        : 'At most '.HospitalSettings::money($room).' can be refunded on this invoice.'));
            }

            $credit = InvoiceCredit::query()->create([
                'uuid' => (string) Str::uuid(),
                'hospital_id' => $locked->hospital_id,
                'invoice_id' => $locked->id,
                'patient_id' => $locked->patient_id,
                'kind' => $kind,
                'status' => CreditStatus::Pending,
                'amount' => $amount,
                'reason' => $reason,
                'requested_by' => $by->id,
                'requested_at' => Carbon::now(),
            ] + $extra);

            return $by->can('billing.refund') ? $this->approveLocked($credit, $locked, $by) : $credit;
        }));
    }

    /** The money moment. Caller holds the locks on both rows. */
    private function approveLocked(InvoiceCredit $credit, Invoice $invoice, User $by): InvoiceCredit
    {
        app(FinancialYearService::class)->assertPostingAllowed(Carbon::now());
        $this->assertOpenToCredit($invoice);

        $amount = (string) $credit->amount;
        $taxPart = '0.00';
        $cardRecordId = null;

        if ($credit->kind === CreditKind::CreditNote) {
            $room = $invoice->netTotal();
            if (bccomp($amount, $room, 2) > 0) {
                throw new RuntimeException('Only '.HospitalSettings::money($room).' is left on this invoice to credit.');
            }
            // The tax goes back in the same share it was charged.
            if (bccomp((string) $invoice->total, '0', 2) > 0 && bccomp((string) $invoice->tax_total, '0', 2) > 0) {
                $taxPart = bcdiv(bcmul($amount, (string) $invoice->tax_total, 6), (string) $invoice->total, 2);
            }
            $invoice->credited = bcadd((string) $invoice->credited, $amount, 2);
        } else {
            $room = $invoice->refundable();
            if (bccomp($amount, $room, 2) > 0) {
                throw new RuntimeException(bccomp($room, '0', 2) === 0
                    ? 'Nothing has been paid beyond what this invoice asks for. Credit it first, then refund.'
                    : 'Only '.HospitalSettings::money($room).' can be refunded on this invoice.');
            }
            $invoice->refunded = bcadd((string) $invoice->refunded, $amount, 2);
        }

        $number = $this->number($credit->kind);

        if ($credit->kind === CreditKind::Refund && $credit->method === PaymentMethod::Card) {
            $card = PatientCard::query()->findOrFail($credit->patient_card_id);
            // Posted once, with the refund: the card's own entry stays out of the books.
            $cardRecordId = $this->cards->credit($card, $amount, 'Refund '.$number, $by->id, [
                'for' => (int) $invoice->patient_id, 'reference' => $number, 'books' => false,
            ])->id;
        }

        $this->settle($invoice);

        $credit->update([
            'status' => CreditStatus::Approved,
            'number' => $number,
            'tax_part' => $taxPart,
            'card_record_id' => $cardRecordId,
            'decided_by' => $by->id,
            'decided_at' => Carbon::now(),
        ]);

        $this->bookkeeper->creditApproved($credit);

        // Credited down to nothing: the visit may now be settled and close itself.
        if ($invoice->visit !== null) {
            app(VisitService::class)->reconcile($invoice->visit, $by->id);
        }

        return $credit->refresh();
    }

    /** Balance and status from the four running figures. */
    private function settle(Invoice $invoice): void
    {
        $owed = bcsub($invoice->netTotal(), $invoice->held(), 2);
        $balance = $this->floor($owed);

        $invoice->balance = $balance;
        $invoice->status = match (true) {
            bccomp($balance, '0', 2) === 0 => InvoiceStatus::Paid,
            bccomp($invoice->held(), '0', 2) > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };
        $invoice->save();
    }

    private function assertOpenToCredit(Invoice $invoice): void
    {
        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true)) {
            throw new RuntimeException("Invoice is {$invoice->status->label()}; there is nothing to credit or refund.");
        }
    }

    private function assertMayApprove(User $by): void
    {
        if (! $by->can('billing.refund')) {
            throw new RuntimeException('Only someone who approves refunds can decide this.');
        }
    }

    /** CN-2026-00001 / RF-2026-00001: gap-free per hospital, kind and year. */
    private function number(CreditKind $kind): string
    {
        $year = Carbon::now()->format('Y');
        $seq = Sequence::next($kind->value, $year, fn () => InvoiceCredit::query()
            ->where('kind', $kind->value)->where('number', 'like', $kind->prefix().'-'.$year.'-%')->count());

        return $kind->prefix().'-'.$year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    private function floor(string $amount): string
    {
        return bccomp($amount, '0', 2) > 0 ? bcadd($amount, '0', 2) : '0.00';
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    private function within(int $hospitalId, \Closure $work): mixed
    {
        return app(CurrentHospital::class)->within($hospitalId, $work);
    }
}
