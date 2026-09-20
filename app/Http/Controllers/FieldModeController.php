<?php

namespace App\Http\Controllers;

use App\Support\CurrentHospital;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The door into Field Mode.
 *
 * This controller renders a SHELL and nothing else. It carries no patient
 * data, no CSRF token and nothing session-dependent, because the page has to
 * come out of the service-worker cache and render with no server involved at
 * all — that is the whole point of it (plan §14).
 *
 * The only things it passes are the two ids the client needs to open the right
 * local database: one database per (hospital, user) means logging out is a
 * delete rather than a careful sweep, and a shared workstation cannot leak one
 * clinician's cache into the next person's session (plan §7).
 */
class FieldModeController extends Controller
{
    public function __invoke(Request $request, CurrentHospital $current): View
    {
        $user = $request->user();

        abort_if($current->id() === null, 403, 'Offline mode needs a hospital context.');

        return view('field.shell', [
            'hospitalId' => $current->id(),
            'userId' => $user->id,
            'userName' => $user->name,
        ]);
    }
}
