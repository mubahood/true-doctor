<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NursingNoteRequest extends FormRequest
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
     * One validation source for a nursing note — used by this request and by the
     * lazy panel App\Livewire\Admissions\Panels\NursingNotes (plan §4.5).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return ['note' => ['required', 'string', 'max:5000']];
    }
}
