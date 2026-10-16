<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\DocumentBrand;
use App\Support\HospitalSettings;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Links a patient can open without signing in, and the structured receipt a
 * thermal printer draws from.
 *
 * A link is a Laravel temporary signed URL to a public PDF route. It carries
 * the document's uuid and the invoice's `share_nonce` - nothing that names a
 * person. Revoking is changing the nonce: every link issued before stops
 * working at once, whatever its expiry (the signature still verifies, the
 * nonce no longer matches). A receipt belongs to its invoice, so revoking the
 * invoice revokes its receipts' links too.
 */
class InvoiceShareService
{
    public const DAYS = 7;

    /** @return array{url:string,expires_at:string,phone:?string,whatsapp_text:string,sms_text:string} */
    public function shareInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('patient');
        $expires = now()->addDays(self::DAYS);
        $url = URL::temporarySignedRoute('share.invoice', $expires, ['uuid' => $invoice->uuid, 'nonce' => $this->nonceOf($invoice)]);

        $hospital = $this->hospitalName();
        $first = $invoice->patient?->first_name;
        $owing = bccomp((string) $invoice->balance, '0', 2) > 0
            ? ' Balance: '.HospitalSettings::money($invoice->balance).'.'
            : ' Paid in full.';

        return $this->envelope(
            $url, $expires, $invoice->patient?->phone_1,
            ($first ? "Dear {$first}, " : '')."here is your invoice {$invoice->invoice_no} from {$hospital}.{$owing} Open it here (valid ".self::DAYS." days): {$url}",
            "{$hospital}: invoice {$invoice->invoice_no}.{$owing} {$url}",
        );
    }

    /** @return array{url:string,expires_at:string,phone:?string,whatsapp_text:string,sms_text:string} */
    public function sharePayment(Payment $payment): array
    {
        $payment->loadMissing('invoice.patient');
        $invoice = $payment->invoice;
        $expires = now()->addDays(self::DAYS);
        $url = URL::temporarySignedRoute('share.receipt', $expires, ['uuid' => $payment->uuid, 'nonce' => $this->nonceOf($invoice)]);

        $hospital = $this->hospitalName();
        $first = $invoice->patient?->first_name;
        $amount = HospitalSettings::money($payment->amount);

        return $this->envelope(
            $url, $expires, $invoice->patient?->phone_1,
            ($first ? "Dear {$first}, " : '')."thank you. {$hospital} received {$amount} against invoice {$invoice->invoice_no}. Your receipt (valid ".self::DAYS." days): {$url}",
            "{$hospital}: received {$amount} for invoice {$invoice->invoice_no}. Receipt: {$url}",
        );
    }

    /** Every link issued for this invoice (and its receipts) stops working. */
    public function revoke(Invoice $invoice): void
    {
        $this->writeNonce($invoice, Str::random(16));
    }

    /** Whether a presented nonce is the invoice's current one. */
    public function accepts(Invoice $invoice, string $nonce): bool
    {
        return $invoice->share_nonce !== null && hash_equals((string) $invoice->share_nonce, $nonce);
    }

    /**
     * A receipt as data, for a thermal printer: brand, lines, totals, how it
     * was paid, what is left and what to put in the QR code.
     *
     * @return array<string,mixed>
     */
    public function receiptJson(Payment $payment): array
    {
        $payment->loadMissing(['invoice.patient', 'invoice.items', 'receivedBy', 'card']);
        $invoice = $payment->invoice;
        $brand = app(DocumentBrand::class)->resolve();

        return [
            'uuid' => $payment->uuid,
            'receipt_no' => 'R'.strtoupper(substr(str_replace('-', '', (string) $payment->uuid), 0, 8)),
            'brand' => [
                'name' => $brand['name'],
                'address' => $brand['address'],
                'phone' => $brand['phone'],
                'email' => $brand['email'],
                'registration' => $brand['registration'],
            ],
            'currency' => $invoice->currency,
            'invoice_no' => $invoice->invoice_no,
            'patient' => ['name' => $invoice->patient?->full_name, 'patient_no' => $invoice->patient?->patient_no],
            'lines' => $invoice->items->map(fn ($i) => [
                'description' => $i->description,
                'quantity' => (string) $i->quantity,
                'unit_price' => (string) $i->unit_price,
                'line_total' => (string) $i->line_total,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (string) $invoice->subtotal,
                'discount' => (string) $invoice->discount,
                'tax' => (string) $invoice->tax_total,
                'total' => (string) $invoice->total,
                'paid_this_payment' => (string) $payment->amount,
                'paid_to_date' => (string) $invoice->amount_paid,
            ],
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'reference' => $payment->reference,
            'card' => $payment->card?->masked(),
            'balance' => (string) $payment->balance_after,
            'settled' => bccomp((string) $payment->balance_after, '0', 2) === 0,
            'received_by' => $payment->receivedBy?->name,
            'paid_at' => $payment->created_at?->toIso8601String(),
            'qr_payload' => 'td:receipt:'.$payment->uuid.':'.$payment->amount.':'.$invoice->currency,
            'footer' => $brand['footer'] ?: 'Thank you.',
        ];
    }

    private function nonceOf(Invoice $invoice): string
    {
        if (blank($invoice->share_nonce)) {
            $this->writeNonce($invoice, Str::random(16));
        }

        return (string) $invoice->share_nonce;
    }

    /** Written below Eloquent: a share link is not a change to the bill, so no timestamp, no sync revision. */
    private function writeNonce(Invoice $invoice, string $nonce): void
    {
        Invoice::query()->whereKey($invoice->getKey())->toBase()->update(['share_nonce' => $nonce]);
        $invoice->setAttribute('share_nonce', $nonce);
        $invoice->syncOriginalAttribute('share_nonce');
    }

    /** @return array{url:string,expires_at:string,phone:?string,whatsapp_text:string,sms_text:string} */
    private function envelope(string $url, \DateTimeInterface $expires, ?string $phone, string $whatsapp, string $sms): array
    {
        return [
            'url' => $url,
            'expires_at' => \Illuminate\Support\Carbon::instance($expires)->toIso8601String(),
            'phone' => filled($phone) ? trim((string) $phone) : null,
            'whatsapp_text' => $whatsapp,
            'sms_text' => $sms,
        ];
    }

    private function hospitalName(): string
    {
        return app(DocumentBrand::class)->resolve()['name'];
    }
}
