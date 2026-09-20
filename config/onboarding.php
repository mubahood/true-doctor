<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Onboarding gate
    |--------------------------------------------------------------------------
    | When enabled, the hospital owner is redirected to the "Getting started"
    | checklist until the essential configuration is complete (see
    | App\Http\Middleware\RequireOnboarding). Disabled in the test environment
    | so unrelated feature tests aren't funnelled through onboarding; the gate's
    | own behaviour is covered by OnboardingGateTest, which enables it explicitly.
    */
    'gate_enabled' => env('ONBOARDING_GATE', true),
];
