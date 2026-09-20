<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BedTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * One validation source for a bed transfer — used by this request and by the
     * transfer slide-over (App\Livewire\Admissions\Show). The exists() clause is
     * tenant-scoped, so another hospital's bed can never be a transfer target.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'to_bed_id' => ['required', 'integer', Rule::exists('beds', 'id')->where('hospital_id', $hospitalId)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
