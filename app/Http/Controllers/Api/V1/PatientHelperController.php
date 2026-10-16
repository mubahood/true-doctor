<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\DuplicateFinder;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Small helpers for the registration form. */
class PatientHelperController extends Controller
{
    public function possibleDuplicates(Request $request, DuplicateFinder $finder): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $data = $request->validate([
            'first' => ['nullable', 'string', 'max:100', 'required_without_all:last,phone'],
            'last' => ['nullable', 'string', 'max:100'],
            'dob' => ['nullable', 'date_format:Y-m-d'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        return ApiResponse::success(['candidates' => $finder->find($data)]);
    }
}
