<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password — the forced change after a temporary
     * password, and any other form that PUTs here.
     *
     * Errors go to the DEFAULT bag. They used to go to a named one
     * ('updatePassword') that the page never read, so a rejected password
     * simply reloaded the form with no message: somebody could not get past
     * this screen and could not see why.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed', 'different:current_password'],
        ], [
            'current_password.required' => 'Enter the password you signed in with.',
            'current_password.current_password' => 'That is not the password you signed in with.',
            'password.required' => 'Choose a new password.',
            'password.min' => 'The new password needs at least :min characters.',
            'password.confirmed' => 'The two new passwords do not match.',
            'password.different' => 'Choose a password different from the one you signed in with.',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $wasForced = $user->password_change_required;

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'password_change_required' => false,
        ])->save();

        if ($wasForced) {
            return redirect()->route('admin.dashboard')->with('status', 'Your password is set. Welcome.');
        }

        return back()->with('status', 'password-updated');
    }
}
