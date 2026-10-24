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
    /** The largest file accepted, in KB (10 MB). The app reads it to say so before sending. */
    public const MAX_KB = 10240;

    /** What a document may be: a PDF, a picture or a Word file. */
    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];

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
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimes:'.implode(',', self::MIMES)],
        ];
    }
}
