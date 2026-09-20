<?php

namespace App\Http\Requests;

use App\Enums\ResultFlag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class LabResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via lab.process
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * One source of truth for a lab result (plan §4.5): App\Livewire\LabOrders\Show
     * prefixes these with "results.{itemId}." for its inline, per-item entry.
     *
     * @return array<string,list<mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'result_value' => ['nullable', 'string', 'max:120'],
            'result_flag' => ['nullable', new Enum(ResultFlag::class)],
            'result_notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
