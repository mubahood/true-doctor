<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor($this->route('subscription')?->id);
    }

    /**
     * The single validation source for platform subscriptions — used by this
     * request and by the Livewire modal (App\Livewire\Super\Subscriptions\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        return [
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', Rule::enum(\App\Enums\SubscriptionStatus::class)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'trial_ends_at' => ['nullable', 'date'],
        ];
    }
}
