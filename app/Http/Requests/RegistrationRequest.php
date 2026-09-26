<?php

namespace App\Http\Requests;

use App\Rules\PlainName;
use App\Support\HumanCheck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Public hospital self-registration (starts a trial on the chosen plan). */
class RegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hospital_name' => ['required', 'string', 'max:120', new PlainName, Rule::unique('hospitals', 'name')],
            'name' => ['required', 'string', 'max:120', new PlainName],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ] + HumanCheck::rules('register');
    }

    public function messages(): array
    {
        return HumanCheck::messages();
    }
}
