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
            // WhatsApp is how we help a new owner set up. Required on the web form;
            // optional over the API so an installed app that does not ask yet still signs people up.
            'whatsapp' => [$this->is('api/*') ? 'nullable' : 'required', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 ()\-]{7,18}$/'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ] + HumanCheck::rules('register');
    }

    public function messages(): array
    {
        return [
            'whatsapp.required' => 'Please give us your WhatsApp number, with the country code, so we can help you set up.',
            'whatsapp.regex' => 'Enter the number with the country code, for example +256 7XX XXX XXX.',
        ] + HumanCheck::messages();
    }
}
