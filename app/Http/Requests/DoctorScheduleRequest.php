<?php

namespace App\Http\Requests;

use App\Enums\Weekday;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Create/update a doctor availability window. Doctor and room are validated to
 * belong to the current hospital (raw exists rules scoped by hand — see the
 * dependents/department decisions).
 */
class DoctorScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via DoctorSchedulePolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('schedule')?->id);
    }

    /**
     * The single validation source for availability windows — shared by this
     * request and the Livewire modal (App\Livewire\Schedules\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('hospital_id', $hospitalId)],
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->where('hospital_id', $hospitalId)],
            'weekday' => ['required', new Enum(Weekday::class)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
