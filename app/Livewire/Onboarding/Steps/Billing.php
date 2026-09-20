<?php

namespace App\Livewire\Onboarding\Steps;

use App\Http\Requests\BillingSettingRequest;
use App\Support\HospitalSettings;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Setup step: currency and invoice numbering. Deliberately narrower than the
 * full billing settings page — onboarding asks only for what cannot be guessed,
 * and the sample amount re-formats live so the choice is obvious before saving.
 * Tax and fees stay on the full page, linked from the step.
 */
class Billing extends Component
{
    use InteractsWithSetup;

    private const SAMPLE = '1234567.89';

    public string $currency_code = 'UGX';

    public ?string $currency_symbol = null;

    public string $currency_position = 'before';

    public int $decimals = 0;

    public string $invoice_prefix = 'INV';

    public function mount(HospitalSettings $settings): void
    {
        $this->authorizeSetup();

        $billing = $settings->billing();
        $this->currency_code = (string) $billing['currency_code'];
        $this->currency_symbol = $billing['currency_symbol'] !== null ? (string) $billing['currency_symbol'] : null;
        $this->currency_position = (string) $billing['currency_position'];
        $this->decimals = (int) $billing['decimals'];
        $this->invoice_prefix = (string) $billing['invoice_prefix'];
    }

    /** Picking a currency fills in the symbol and decimals that go with it. */
    public function updatedCurrencyCode(): void
    {
        $this->currency_code = strtoupper(trim($this->currency_code));
        $meta = HospitalSettings::CURRENCY_META[$this->currency_code] ?? null;

        if ($meta !== null) {
            $this->currency_symbol = $meta['symbol'];
            $this->decimals = $meta['decimals'];
        }
    }

    protected function rules(): array
    {
        return array_intersect_key(
            BillingSettingRequest::rulesFor(),
            array_flip(['currency_code', 'currency_symbol', 'currency_position', 'decimals', 'invoice_prefix']),
        );
    }

    /** Live preview of a real amount in the format being chosen. */
    #[Computed]
    public function preview(): string
    {
        $decimals = in_array($this->decimals, [0, 2, 3], true) ? $this->decimals : 2;
        $number = number_format((float) self::SAMPLE, $decimals, '.', ',');
        $symbol = ($this->currency_symbol ?: strtoupper($this->currency_code)) ?: '';

        return $this->currency_position === 'after' ? trim("{$number} {$symbol}") : trim("{$symbol}{$number}");
    }

    /** @return array<string,string> code => label */
    #[Computed]
    public function currencies(): array
    {
        $out = [];
        foreach (HospitalSettings::CURRENCY_META as $code => $meta) {
            $out[$code] = $code.' — '.$meta['symbol'];
        }

        return $out;
    }

    public function save(): void
    {
        $this->authorizeSetup();
        $this->currency_code = strtoupper(trim($this->currency_code));
        $data = $this->validate();

        $hospital = $this->hospital();
        $existing = is_array($hospital->settings['billing'] ?? null) ? $hospital->settings['billing'] : [];

        $hospital->currency = $data['currency_code'];
        $hospital->settings = array_merge($hospital->settings ?? [], [
            'billing' => array_merge(app(HospitalSettings::class)->billing(), $existing, $data),
        ]);
        $hospital->save();

        unset($this->preview);
        $this->stepCompleted('Currency and invoice numbering saved.');
    }

    public function render()
    {
        $this->authorizeSetup();

        return view('livewire.onboarding.steps.billing');
    }
}
