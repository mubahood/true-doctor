<?php

namespace App\Livewire\Settings;

use App\Http\Requests\BillingSettingRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\HospitalSettings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The hospital owner/admin configures their own billing: currency, tax, default
 * visit fee and invoice formatting. Everything money-facing reads back
 * through HospitalSettings, so changing it here re-formats the whole app — which
 * is why the form carries a LIVE sample of the formatted amount that updates as
 * the currency, decimals, separators and symbol position change.
 *
 * Validation is BillingSettingRequest::rulesFor() — the same rules the HTTP/API
 * path used (house rule 13) — plus one this page adds: the two separators have
 * to differ, or an amount reads as "1.234.567.89".
 *
 * @property-read string $preview
 * @property-read array{invoices:int, payments:int, currency:string} $recorded
 */
#[Layout('layouts.admin')]
class Billing extends Component
{
    /** A representative amount for the live format preview. */
    private const SAMPLE = '1234567.89';

    public string $currency_code = '';

    public ?string $currency_symbol = null;

    public string $currency_position = 'before';

    public int $decimals = 2;

    public ?string $thousands_separator = ',';

    public string $decimal_separator = '.';

    public bool $tax_enabled = false;

    public ?string $tax_label = null;

    public ?string $tax_rate = null;

    public ?string $consultation_fee = null;

    public string $invoice_prefix = 'INV';

    public ?string $invoice_footer = null;

    public function mount(HospitalSettings $settings): void
    {
        $this->authorizeManage();

        $billing = $settings->billing();
        $this->currency_code = (string) $billing['currency_code'];
        $this->currency_symbol = $billing['currency_symbol'] !== null ? (string) $billing['currency_symbol'] : null;
        $this->currency_position = (string) $billing['currency_position'];
        $this->decimals = (int) $billing['decimals'];
        $this->thousands_separator = $billing['thousands_separator'] !== null ? (string) $billing['thousands_separator'] : null;
        $this->decimal_separator = (string) $billing['decimal_separator'];
        $this->tax_enabled = (bool) $billing['tax_enabled'];
        $this->tax_label = $billing['tax_label'] !== null ? (string) $billing['tax_label'] : null;
        $this->tax_rate = $billing['tax_rate'] !== null ? (string) $billing['tax_rate'] : null;
        $this->consultation_fee = $billing['consultation_fee'] !== null ? (string) $billing['consultation_fee'] : null;
        $this->invoice_prefix = (string) $billing['invoice_prefix'];
        $this->invoice_footer = $billing['invoice_footer'] !== null ? (string) $billing['invoice_footer'] : null;
    }

    private function authorizeManage(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
    }

    protected function rules(): array
    {
        return array_merge(BillingSettingRequest::rulesFor(), [
            // "1.234.567.89" is not a number anybody can read, and nothing
            // stopped it: both separators were validated, neither against the
            // other, and the result would have gone onto every invoice.
            'decimal_separator' => ['required', 'string', 'max:1', 'different:thousands_separator'],
        ]);
    }

    /** @return array<string,string> */
    protected function messages(): array
    {
        return [
            'decimal_separator.different' => 'The two separators have to differ, or an amount reads as "1.234.567.89".',
        ];
    }

    // ── What is already recorded in the current currency ─────────────────

    /**
     * How much money is already on the books, and in what.
     *
     * Changing the currency code RE-LABELS every stored amount; it does not
     * convert them. A hospital with four hundred invoices in shillings that
     * switches to dollars has just turned USh 30,000 into $30,000 on every one
     * of them. The onboarding step has always said so in a docblock; the page
     * where it can actually be done said nothing at all.
     *
     * @return array{invoices:int, payments:int, currency:string}
     */
    #[Computed]
    public function recorded(): array
    {
        $settings = app(HospitalSettings::class);
        $hospital = $settings->hospital();

        return [
            'invoices' => $hospital === null ? 0 : Invoice::count(),
            'payments' => $hospital === null ? 0 : Payment::count(),
            'currency' => strtoupper((string) ($hospital?->currency ?: $settings->get('currency_code'))),
        ];
    }

    /** Is the admin about to re-label money that already exists? */
    public function currencyIsChanging(): bool
    {
        $recorded = $this->recorded;

        return ($recorded['invoices'] > 0 || $recorded['payments'] > 0)
            && $recorded['currency'] !== ''
            && strtoupper(trim($this->currency_code)) !== $recorded['currency'];
    }

    // ── Live previews, for everything that shapes an invoice ─────────────

    /** The known currencies, for one-click setup. @return array<string,array{symbol:string,decimals:int}> */
    public function currencies(): array
    {
        return HospitalSettings::CURRENCY_META;
    }

    /**
     * Take a currency whole — its symbol and its decimal places with it.
     *
     * A shilling has no cents and a dollar has two; leaving the admin to work
     * that out from three separate fields is how a UGX hospital ends up
     * printing "USh 30,000.00".
     */
    public function useCurrency(string $code): void
    {
        $code = strtoupper(trim($code));
        $meta = HospitalSettings::CURRENCY_META[$code] ?? null;

        if ($meta === null) {
            return;
        }

        $this->currency_code = $code;
        $this->currency_symbol = $meta['symbol'];
        $this->decimals = $meta['decimals'];

        unset($this->preview);
    }

    /** What the prefix actually produces, which is what people are choosing. */
    public function invoicePreview(): string
    {
        $prefix = trim($this->invoice_prefix) !== '' ? trim($this->invoice_prefix) : 'INV';

        return $prefix.'-'.now()->format('Y').'-00001';
    }

    /**
     * A worked example of the tax, because a rate is not an amount.
     *
     * @return array{net:string, tax:string, gross:string}
     */
    public function taxPreview(): array
    {
        $net = '100000';
        $rate = (string) ($this->tax_rate ?? '0');
        $tax = $this->tax_enabled ? bcdiv(bcmul($net, $rate, 4), '100', 4) : '0';

        return [
            'net' => $this->format($net),
            'tax' => $this->format($tax),
            'gross' => $this->format(bcadd($net, $tax, 4)),
        ];
    }

    /** The same arithmetic as preview(), for any amount. */
    private function format(string $amount): string
    {
        $decimals = in_array($this->decimals, [0, 2, 3], true) ? $this->decimals : 2;
        $number = number_format((float) $amount, $decimals, $this->decimal_separator ?: '.', (string) $this->thousands_separator);
        $symbol = ($this->currency_symbol ?: strtoupper($this->currency_code)) ?: '';

        return $this->currency_position === 'after' ? trim("{$number} {$symbol}") : trim("{$symbol}{$number}");
    }

    /**
     * Live preview of the sample amount under the currently entered format —
     * same arithmetic as HospitalSettings::format(), applied to unsaved input.
     */
    #[Computed]
    public function preview(): string
    {
        return $this->format(self::SAMPLE);
    }

    public function save(HospitalSettings $settings): void
    {
        $this->authorizeManage();

        $this->currency_code = strtoupper(trim($this->currency_code));
        $data = $this->validate();
        $data['tax_enabled'] = $this->tax_enabled;

        $hospital = $settings->hospital();
        abort_if($hospital === null, 404, 'No hospital in context.');

        $stored = $hospital->settings ?? [];

        $hospital->currency = $data['currency_code'];
        // Merged, not replaced: the letterhead page writes the footer line into
        // this same key, and a save here must not drop what it does not ask for.
        $hospital->settings = array_merge($stored, [
            'billing' => array_merge(
                is_array($stored['billing'] ?? null) ? $stored['billing'] : [],
                $data,
            ),
        ]);
        $hospital->save();

        unset($this->preview, $this->recorded);
        $this->dispatch('toast', message: 'Billing settings saved.', type: 'success');
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.settings.billing')->title('Billing settings');
    }
}
