<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RadiologyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via radiology.report
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * One source of truth for the narrative report (plan §4.5):
     * App\Livewire\RadiologyOrders\Show validates with these.
     *
     * @return array<string,list<mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'findings' => ['nullable', 'string', 'max:5000'],
            'impression' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
