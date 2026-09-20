<?php

namespace App\Http\Requests;

use App\Enums\DoseSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Write a prescription: a header note + one or more dose-item lines. */
class PrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via PrescriptionPolicy@prescribe
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for writing a prescription — used by this
     * request and by the repeater in App\Livewire\Visits\Panels\Prescriptions.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $slots = array_map(fn (DoseSlot $s) => $s->value, DoseSlot::cases());

        return [
            'notes' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.drug_name' => ['required', 'string', 'max:120'],
            'items.*.dosage' => ['nullable', 'string', 'max:60'],
            'items.*.slots' => ['required', 'array', 'min:1'],
            'items.*.slots.*' => [Rule::in($slots)],
            'items.*.days' => ['required', 'integer', 'min:1', 'max:90'],
            'items.*.start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'items.*.instructions' => ['nullable', 'string', 'max:255'],
        ];
    }
}
