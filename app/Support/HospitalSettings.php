<?php

namespace App\Support;

use App\Models\Hospital;

/**
 * Per-hospital, admin-configurable settings — currency, tax, default fees and
 * invoice formatting — read from the current hospital's `settings` JSON (and
 * `currency` column) merged over sane defaults. Nothing about money is hardcoded:
 * every hospital chooses its own currency code/symbol, decimals, tax rate and
 * invoice prefix. Resolved once per request (singleton over CurrentHospital).
 */
class HospitalSettings
{
    /** @var array<string,mixed> East-Africa-first defaults (Uganda shilling). */
    private const DEFAULTS = [
        'currency_code' => 'UGX',
        'currency_symbol' => 'USh',
        'currency_position' => 'before',   // before | after
        'decimals' => 0,
        'thousands_separator' => ',',
        'decimal_separator' => '.',
        'tax_enabled' => false,
        'tax_label' => 'VAT',
        'tax_rate' => 0,                    // percent, e.g. 18 (Uganda VAT)
        'consultation_fee' => 0,
        'invoice_prefix' => 'INV',
        'invoice_footer' => null,
    ];

    /** Symbol + display decimals derived from a currency code when not overridden. */
    /** Symbol + decimal places per supported currency; also drives the onboarding picker. */
    public const CURRENCY_META = [
        'UGX' => ['symbol' => 'USh', 'decimals' => 0],
        'KES' => ['symbol' => 'KSh', 'decimals' => 0],
        'TZS' => ['symbol' => 'TSh', 'decimals' => 0],
        'RWF' => ['symbol' => 'FRw', 'decimals' => 0],
        'SSP' => ['symbol' => 'SSP', 'decimals' => 0],
        'BIF' => ['symbol' => 'FBu', 'decimals' => 0],
        'ETB' => ['symbol' => 'Br', 'decimals' => 2],
        'USD' => ['symbol' => '$', 'decimals' => 2],
        'EUR' => ['symbol' => '€', 'decimals' => 2],
        'GBP' => ['symbol' => '£', 'decimals' => 2],
    ];

    private ?Hospital $hospital = null;

    private ?int $resolvedFor = null;

    private bool $resolved = false;

    public function __construct(private readonly CurrentHospital $current) {}

    public function hospital(): ?Hospital
    {
        $id = $this->current->id();

        // Re-resolve if the tenant context changed (e.g. a super-admin switching
        // hospitals within one request) — the memo is keyed on the current id.
        if (! $this->resolved || $this->resolvedFor !== $id) {
            $this->hospital = $id !== null ? Hospital::find($id) : null;
            $this->resolvedFor = $id;
            $this->resolved = true;
        }

        return $this->hospital;
    }

    /** @return array<string,mixed> */
    public function billing(): array
    {
        $hospital = $this->hospital();
        $stored = is_array($hospital?->settings['billing'] ?? null) ? $hospital->settings['billing'] : [];

        $merged = array_merge(self::DEFAULTS, $stored);

        // The currency column is authoritative for the code unless overridden in JSON.
        if ($hospital && ! isset($stored['currency_code']) && $hospital->currency) {
            $merged['currency_code'] = $hospital->currency;
        }

        // Derive the symbol and display decimals from the resolved code unless the
        // admin has explicitly set them — so a UGX hospital shows "USh", not "$".
        $meta = self::CURRENCY_META[strtoupper((string) $merged['currency_code'])] ?? null;
        if ($meta !== null) {
            if (! isset($stored['currency_symbol'])) {
                $merged['currency_symbol'] = $meta['symbol'];
            }
            if (! isset($stored['decimals'])) {
                $merged['decimals'] = $meta['decimals'];
            }
        }

        return $merged;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->billing()[$key] ?? $default;
    }

    public function currencyCode(): string
    {
        return (string) $this->get('currency_code', 'UGX');
    }

    public function taxEnabled(): bool
    {
        return (bool) $this->get('tax_enabled', false);
    }

    /** Tax rate as a scale-4 decimal string ready for bcmath (e.g. "18.0000"). */
    public function taxRate(): string
    {
        return $this->taxEnabled() ? self::decimal($this->get('tax_rate', 0), 4) : '0.0000';
    }

    public function taxLabel(): string
    {
        return (string) $this->get('tax_label', 'Tax');
    }

    /** Default visit fee as a scale-2 decimal string. */
    public function consultationFee(): string
    {
        return self::decimal($this->get('consultation_fee', 0), 2);
    }

    /** Exact decimal string at the given scale — never via float. */
    public static function decimal(mixed $value, int $scale): string
    {
        $value = is_string($value) ? trim($value) : $value;

        return is_numeric($value) ? bcadd((string) $value, '0', $scale) : bcadd('0', '0', $scale);
    }

    public function invoicePrefix(): string
    {
        return (string) $this->get('invoice_prefix', 'INV');
    }

    public function decimals(): int
    {
        return (int) $this->get('decimals', 2);
    }

    /** Format a numeric amount with the hospital's currency for display. */
    public function format(int|float|string|null $amount): string
    {
        $b = $this->billing();
        $number = number_format((float) $amount, (int) $b['decimals'], (string) $b['decimal_separator'], (string) $b['thousands_separator']);
        $symbol = (string) ($b['currency_symbol'] ?: $b['currency_code']);

        return $b['currency_position'] === 'after' ? "{$number} {$symbol}" : "{$symbol}{$number}";
    }

    /** Blade-friendly static: {{ \App\Support\HospitalSettings::money($amount) }}. */
    public static function money(int|float|string|null $amount): string
    {
        return app(self::class)->format($amount);
    }
}
