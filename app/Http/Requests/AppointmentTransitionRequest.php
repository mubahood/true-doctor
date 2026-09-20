<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Move an appointment to a new status. Whether the move is legal is decided by
 * the state machine in AppointmentService::transition (this only checks shape).
 */
class AppointmentTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorizes via AppointmentPolicy
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(AppointmentStatus::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function targetStatus(): AppointmentStatus
    {
        return AppointmentStatus::from($this->validated('status'));
    }
}
