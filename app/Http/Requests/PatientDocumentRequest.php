<?php

namespace App\Http\Requests;

use App\Models\PatientDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload one patient document to the private disk. Type is constrained to the
 * known set; the file itself is validated for size and mime.
 */
class PatientDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via PatientPolicy@manageDocuments
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for document uploads — used by this request
     * and by the Livewire panel (App\Livewire\Patients\Panels\Documents).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'type' => ['required', Rule::in(PatientDocument::TYPES)],
            'note' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ];
    }
}
