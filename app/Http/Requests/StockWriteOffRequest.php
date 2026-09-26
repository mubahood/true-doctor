<?php

namespace App\Http\Requests;

use App\Enums\StockMovementReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A write-off: a loss with its cause named, and a note on top — "expired,
 * 400 tablets" is the entry an auditor asks a follow-up question about.
 * One source for the web's stock screens and the API.
 */
class StockWriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via StockItemPolicy@update
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    public function messages(): array
    {
        return self::messagesFor();
    }

    /** @return array<string, list<mixed>> */
    public static function rulesFor(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => ['required', Rule::in(array_map(fn (StockMovementReason $r) => $r->value, StockMovementReason::losses()))],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /** @return array<string,string> */
    public static function messagesFor(): array
    {
        return [
            'note.required' => 'Say why it is being written off — this is the record somebody audits.',
            'reason.required' => 'Choose what happened to it.',
        ];
    }
}
