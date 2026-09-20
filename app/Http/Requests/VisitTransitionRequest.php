<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Moving a visit takes no target any more.
 *
 * There is only ever one next stage, and whether it can be reached is the
 * visit's own business — the gates in VisitService decide (docs/visits.md).
 * All a caller supplies is a note, and a cancellation's reason is required.
 */
class VisitTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via VisitPolicy@manage
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /** @return array<string, array<int, mixed>> */
    public static function rulesFor(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function cancelRules(): array
    {
        return [
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
