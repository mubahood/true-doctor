<?php

namespace App\Http\Requests\Api;

use App\Enums\CardHolderRelationship;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CardHolderApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('patients.card.manage');
    }

    public function rules(): array
    {
        return [
            'patient_uuid' => ['required', 'uuid', Rule::exists('patients', 'uuid')->where('hospital_id', app(CurrentHospital::class)->id())],
            'relationship' => ['required', new Enum(CardHolderRelationship::class)],
        ];
    }
}
