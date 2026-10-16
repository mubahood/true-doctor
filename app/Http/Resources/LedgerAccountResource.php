<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\LedgerAccount
 *
 * `balance` and `entries` are set by the controller from the services the web
 * chart reads (FinancialStatements::balances, AccountManager::usage).
 */
class LedgerAccountResource extends JsonResource
{
    /** @var array<int,string> */
    public static array $balances = [];

    /** @var array<int,int> */
    public static array $usage = [];

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'label' => $this->label(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'subtype' => $this->subtype,
            'system_key' => $this->system_key,
            'is_system' => $this->isSystem(),
            'is_book' => $this->is_book,
            'is_active' => $this->is_active,
            'description' => $this->description,
            'balance' => self::$balances[$this->id] ?? null,
            'entries' => self::$usage[$this->id] ?? null,
        ];
    }
}
