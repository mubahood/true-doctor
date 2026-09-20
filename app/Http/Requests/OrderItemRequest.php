<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Order a catalogue service (billable line) on a visit. */
class OrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via billing.manage
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for a billable service line — used by this
     * request and by App\Livewire\Visits\Panels\Charges.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('hospital_id', $hospitalId)],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }
}
