<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    /**
     * Update the user's password — the forced change after a temporary
     * password, and any other form that PUTs here.
     *
     * The rules and messages are PasswordChangeRequest's, shared with the app's
     * POST /api/v1/auth/password. Errors go to the DEFAULT bag: they used to go
     * to a named one the page never read, so a rejected password reloaded the
     * form in silence.
     */
    public function update(PasswordChangeRequest $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $wasForced = $user->password_change_required;

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
            'password_change_required' => false,
        ])->save();

        if ($wasForced) {
            return redirect()->route('admin.dashboard')->with('status', 'Your password is set. Welcome.');
        }

        return back()->with('status', 'password-updated');
    }
}
