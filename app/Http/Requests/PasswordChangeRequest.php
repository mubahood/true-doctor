<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Changing your own password — one set of rules and words for every client.
 *
 * The web page (PUT /password) and the app (POST /api/v1/auth/password) both
 * type-hint this, so the rule and the messages a person reads cannot differ
 * between the two. The rule itself is Password::defaults(): at least six
 * characters, nothing more (AppServiceProvider).
 */
class PasswordChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed', 'different:current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter the password you signed in with.',
            'current_password.current_password' => 'That is not the password you signed in with.',
            'password.required' => 'Choose a new password.',
            'password.min' => 'The new password needs at least :min characters.',
            'password.confirmed' => 'The two new passwords do not match.',
            'password.different' => 'Choose a password different from the one you signed in with.',
        ];
    }
}
