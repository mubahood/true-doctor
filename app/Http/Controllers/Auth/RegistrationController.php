<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrationRequest;
use App\Models\Plan;
use App\Services\RegistrationService;
use App\Support\StaffSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Public hospital sign-up. Creates the hospital + owner + a 14-day trial, signs
 * the owner in, and drops them into the onboarding wizard.
 */
class RegistrationController extends Controller
{
    public function __construct(private readonly RegistrationService $service) {}

    public function show(\Illuminate\Http\Request $request)
    {
        $plans = Plan::where('is_active', true)->orderBy('price')->get();

        // The plan in the query string, but only if it is one we are still
        // selling. This URL is shared and edited by hand, and a stale id used
        // to leave every radio unchecked — a form that looks fine and then
        // refuses to submit, complaining about a field the person never saw.
        // `plan` arrives two ways: as an id from a pricing-page button, and
        // as a slug from a Google Ads price asset (`?plan=professional`).
        // Both have to work, and both have to survive being wrong.
        $wanted = $request->query('plan');

        $selected = is_numeric($wanted)
            ? $plans->firstWhere('id', (int) $wanted)?->id
            : $plans->firstWhere('slug', is_string($wanted) ? strtolower(trim($wanted)) : '')?->id;

        return view('auth.register', [
            'plans' => $plans,
            'selectedPlan' => $selected,
        ]);
    }

    public function store(RegistrationRequest $request): RedirectResponse
    {
        $owner = $this->service->register($request->validated());

        // Signed in for the same ninety days as anybody who used the sign-in
        // form. Somebody who has just set up a hospital is the last person
        // who should be asked for the password again next week.
        StaffSession::apply();
        Auth::login($owner, remember: true);
        $request->session()->regenerate();

        return redirect()->route('admin.onboarding')->with('success', 'Welcome! Your 14-day trial has started.');
    }
}
