<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Services\BillingSettingsService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Billing (manage-settings): currency, tax, default consultation
 * fee and invoice numbering. Everything money-facing reads back through
 * HospitalSettings, so a save re-formats the whole app. Changing the currency
 * code re-labels money already recorded; it does not convert it, so the
 * response says when that is what a save just did.
 */
class BillingSettingsController extends Controller
{
    public function __construct(private readonly BillingSettingsService $service, private readonly HospitalSettings $settings) {}

    public function show(Request $request): JsonResponse
    {
        $this->hospital($request);

        return ApiResponse::success($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $hospital = $this->hospital($request);

        // The whole form, as on the web; unsent fields keep their stored value.
        $input = $request->only(array_keys($this->service->rules())) + $this->settings->billing();
        $input['currency_code'] = strtoupper(trim((string) ($input['currency_code'] ?? '')));
        $input['tax_enabled'] = filter_var($input['tax_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $data = validator($input, $this->service->rules(), $this->service->messages())->validate();
        $data['tax_enabled'] = $input['tax_enabled'];

        $relabelled = $this->service->currencyIsChanging($data['currency_code']);

        $this->service->save($hospital, $data);

        return ApiResponse::success(
            $this->payload() + ['currency_relabelled' => $relabelled],
            $relabelled
                ? 'Billing settings saved. Amounts already recorded keep their numbers and now read in '.$data['currency_code'].'; they were not converted.'
                : 'Billing settings saved.',
        );
    }

    private function hospital(Request $request): Hospital
    {
        abort_unless($request->user()->can('manage-settings'), 403);

        $hospital = $this->settings->hospital();
        abort_if($hospital === null, 404, 'No hospital in context.');

        return $hospital;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        $billing = $this->settings->billing();

        return [
            'settings' => [
                'currency_code' => strtoupper((string) $billing['currency_code']),
                'currency_symbol' => $billing['currency_symbol'] !== null ? (string) $billing['currency_symbol'] : null,
                'currency_position' => (string) $billing['currency_position'],
                'decimals' => (int) $billing['decimals'],
                'thousands_separator' => $billing['thousands_separator'] !== null ? (string) $billing['thousands_separator'] : null,
                'decimal_separator' => (string) $billing['decimal_separator'],
                'tax_enabled' => (bool) $billing['tax_enabled'],
                'tax_label' => $billing['tax_label'] !== null ? (string) $billing['tax_label'] : null,
                'tax_rate' => $billing['tax_rate'] !== null ? (string) $billing['tax_rate'] : null,
                'consultation_fee' => $billing['consultation_fee'] !== null ? (string) $billing['consultation_fee'] : null,
                'invoice_prefix' => (string) $billing['invoice_prefix'],
                'invoice_footer' => $billing['invoice_footer'] !== null ? (string) $billing['invoice_footer'] : null,
            ],
            // A sample amount written the way the saved settings write money.
            'preview' => HospitalSettings::money('1234567.89'),
            'invoice_example' => $this->settings->invoicePrefix().'-'.now()->format('Y').'-00001',
            // Money already on the books: changing the code relabels it, never converts it.
            'recorded' => $this->service->recorded(),
            'currencies' => HospitalSettings::CURRENCY_META,
        ];
    }
}
