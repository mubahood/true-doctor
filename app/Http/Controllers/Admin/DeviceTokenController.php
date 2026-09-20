<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Registers a push device token for the signed-in staff member (JSON). */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:191'],
            'platform' => ['required', 'in:web,android,ios'],
        ]);

        // Keyed on (user, token): a caller can only ever register or refresh
        // a token for their own account, never re-point another user's device.
        DeviceToken::updateOrCreate(
            ['user_id' => $request->user()->id, 'token' => $data['token']],
            ['platform' => $data['platform'], 'last_used_at' => now()],
        );

        return response()->json(['status' => 'registered']);
    }
}
