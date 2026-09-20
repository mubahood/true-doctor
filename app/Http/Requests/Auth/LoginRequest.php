<?php

namespace App\Http\Requests\Auth;

use App\Support\StaffSession;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Whether to issue the long-lived cookie.
     *
     * Defaulted ON, because the checkbox on the form is ticked: a browser that
     * posts the field unticked sends nothing at all, which is indistinguishable
     * from a client that never had the field. That ambiguity is resolved in
     * favour of the box as drawn — a hidden companion field makes the two cases
     * distinguishable, so an unticked box really does mean "this shift only".
     */
    public function remembers(): bool
    {
        // The hidden field is posted by every form that draws the checkbox.
        // Its absence means an older client, or a direct POST, and those get
        // the default the screen promises.
        if (! $this->has('remember_present')) {
            return true;
        }

        return $this->boolean('remember');
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // The remember window is a hospital policy, not a Laravel default
        // (which is five years). Set here, immediately before the attempt, so
        // the guard is already resolved and the session already started.
        StaffSession::apply();

        try {
            $authenticated = Auth::attempt($this->only('email', 'password'), $this->remembers());
        } catch (\RuntimeException $e) {
            // Catches "This password does not use the Bcrypt algorithm" for legacy accounts
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages([
                'email' => 'These credentials are not valid.',
            ]);
        }

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
