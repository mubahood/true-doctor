<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     *
     * The notification is queued (see `User::sendEmailVerificationNotification`),
     * so in a normal deployment nothing here touches SMTP at all. This still
     * catches, for two reasons:
     *
     *  - the queue driver is `sync` in tests and in some small installations,
     *    which puts the send back inside the request;
     *  - a broker that is itself unreachable throws on dispatch.
     *
     * Either way the user gets a sentence they can act on instead of a stack
     * trace. They clicked a button asking for an email; the outcome is either
     * "it is on its way" or "it could not be sent, try again" — never a
     * Symfony exception page.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('admin.dashboard', absolute: false));
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            // Everything, deliberately: an SMTP refusal, a queue broker that is
            // itself down, a DNS failure. None of them is the user's problem
            // and none of them should be their error page.
            // Logged rather than shown: the reason is a mail-server
            // configuration problem, and the address and credentials in it are
            // no business of whoever is trying to verify their email.
            report($e);

            return back()->with('status', 'verification-link-failed');
        }

        return back()->with('status', 'verification-link-sent');
    }
}
