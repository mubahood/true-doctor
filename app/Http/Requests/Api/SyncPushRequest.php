<?php

namespace App\Http\Requests\Api;

use App\Services\Sync\SyncEngine;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of a push.
 *
 * Only the ENVELOPE is validated here, not the payloads. Each entity's payload
 * is checked by its own handler against the same FormRequest rules the online
 * form uses, because a payload that fails validation must be rejected as ONE
 * operation with a reason the device can show beside the field — not as a 422
 * that fails the whole batch and tells a nurse nothing (plan §9.5).
 */
class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller and each handler authorize
    }

    public function rules(): array
    {
        return [
            'protocol_version' => ['nullable', 'integer'],
            'device_uuid' => ['nullable', 'uuid'],

            // A batch, capped. More than this is refused outright rather than
            // truncated: silently dropping the tail of a batch would leave the
            // device believing work was sent that never was.
            'operations' => ['required', 'array', 'min:1', 'max:'.SyncEngine::maxBatch()],

            'operations.*.operation_id' => ['required', 'string', 'max:40'],
            'operations.*.entity' => ['required', 'string', 'max:60'],
            'operations.*.entity_uuid' => ['required', 'uuid'],
            'operations.*.operation' => ['required', 'string', 'in:create,update,delete,intent'],
            'operations.*.payload' => ['present', 'array'],
            'operations.*.base_version' => ['nullable', 'integer', 'min:0'],
            // What the device last had CONFIRMED for the fields it changed —
            // the third side of a three-way merge (plan §10.1).
            'operations.*.base_fields' => ['nullable', 'array'],
            'operations.*.client_created_at' => ['nullable', 'date'],
            'operations.*.atomic_group_id' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'operations.max' => 'Too many operations in one batch. Send them in smaller groups — nothing is lost.',
            'operations.*.operation_id.required' => 'Every operation must carry an operation_id, or a retry could apply it twice.',
        ];
    }
}
