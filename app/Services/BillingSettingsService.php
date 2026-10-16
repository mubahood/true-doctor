<?php

namespace App\Services;

use App\Http\Requests\BillingSettingRequest;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\HospitalSettings;

/**
 * A hospital's billing configuration — currency, tax, default fee and invoice
 * formatting (Settings → Billing). Shared by that page and the API; everything
 * money-facing reads back through HospitalSettings.
 */
class BillingSettingsService
{
    public function __construct(private readonly HospitalSettings $settings) {}

    /**
     * BillingSettingRequest's rules, plus the one this page adds: the two
     * separators have to differ, or an amount reads as "1.234.567.89".
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_merge(BillingSettingRequest::rulesFor(), [
            'decimal_separator' => ['required', 'string', 'max:1', 'different:thousands_separator'],
        ]);
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'decimal_separator.different' => 'The two separators have to differ, or an amount reads as "1.234.567.89".',
        ];
    }

    /**
     * How much money is already on the books, and in what. Changing the
     * currency code RE-LABELS every stored amount; it does not convert them.
     *
     * @return array{invoices:int, payments:int, currency:string}
     */
    public function recorded(): array
    {
        $hospital = $this->settings->hospital();

        return [
            'invoices' => $hospital === null ? 0 : Invoice::count(),
            'payments' => $hospital === null ? 0 : Payment::count(),
            'currency' => strtoupper((string) ($hospital?->currency ?: $this->settings->get('currency_code'))),
        ];
    }

    /** Is a save with this code about to re-label money that already exists? */
    public function currencyIsChanging(string $code): bool
    {
        $recorded = $this->recorded();

        return ($recorded['invoices'] > 0 || $recorded['payments'] > 0)
            && $recorded['currency'] !== ''
            && strtoupper(trim($code)) !== $recorded['currency'];
    }

    /**
     * Save the validated settings. Merged, not replaced: the letterhead page
     * writes the footer line into the same key.
     *
     * @param  array<string,mixed>  $data
     */
    public function save(Hospital $hospital, array $data): Hospital
    {
        $stored = $hospital->settings ?? [];

        $hospital->currency = $data['currency_code'];
        $hospital->settings = array_merge($stored, [
            'billing' => array_merge(
                is_array($stored['billing'] ?? null) ? $stored['billing'] : [],
                $data,
            ),
        ]);
        $hospital->save();

        return $hospital;
    }
}
