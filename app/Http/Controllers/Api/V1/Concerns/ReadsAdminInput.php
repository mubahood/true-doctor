<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Shared plumbing for the back-office catalogue endpoints (departments, rooms,
 * wards, beds, staff profiles): the web's slide-over form is always a whole
 * record, so an update here starts from the stored record and applies the
 * fields that were sent — a client changing one field cannot blank the others
 * (or switch a department off by leaving `is_active` out).
 */
trait ReadsAdminInput
{
    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->query('per_page', 20), 100));
    }

    /**
     * The form as it will be validated: stored values (when editing) under
     * whatever the request sent, enums as their values.
     *
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    protected function formInput(Request $request, array $fields, ?Model $model = null): array
    {
        $input = [];
        if ($model !== null) {
            foreach ($fields as $field) {
                $value = $model->{$field};
                $input[$field] = $value instanceof \BackedEnum ? $value->value : $value;
            }
        }

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $input[$field] = $request->input($field);
            }
        }

        return $input;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed> the validated fields
     */
    protected function validated(array $input, array $rules): array
    {
        return Validator::make($input, $rules)->validate();
    }

    protected function refused(string $message, string $field = 'base'): JsonResponse
    {
        return ApiResponse::error(ApiErrorCode::ValidationFailed, $message, 422, [$field => [$message]]);
    }

    protected function forbiddenLimit(string $message): JsonResponse
    {
        return ApiResponse::error(ApiErrorCode::Forbidden, $message, 403);
    }
}
