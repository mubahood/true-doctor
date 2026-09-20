<?php

namespace App\Http\Requests;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via RoomPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('room')?->id);
    }

    /**
     * The single validation source for rooms — used by this request and by the
     * Livewire modal (App\Livewire\Rooms\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'name' => ['required', 'string', 'max:120',
                Rule::unique('rooms', 'name')
                    ->where(fn ($q) => $q->where('hospital_id', $hospitalId)->whereNull('deleted_at'))
                    ->ignore($ignoreId),
            ],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('hospital_id', $hospitalId)],
            'type' => ['required', new Enum(RoomType::class)],
            'status' => ['required', new Enum(RoomStatus::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
