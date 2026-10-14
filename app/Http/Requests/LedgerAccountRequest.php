<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update an account in the chart. Code unique per hospital. */
class LedgerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.manage');
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('account')?->id);
    }

    /**
     * The single validation source for accounts — shared by this request and
     * the Livewire chart of accounts (App\Livewire\Finance\Accounts).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'code' => ['required', 'string', 'max:12', 'regex:/^[0-9A-Za-z.\-]+$/',
                Rule::unique('ledger_accounts', 'code')->where(fn ($q) => $q->where('hospital_id', $hospitalId))->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'is_book' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
