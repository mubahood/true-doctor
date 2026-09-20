<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription grace period
    |--------------------------------------------------------------------------
    |
    | Days past a subscription's `ends_at` that tenant routes stay reachable
    | before EnsureSubscribed blocks them. HMS_PLAN.md §2.1.
    |
    */
    'subscription_grace_days' => env('SUBSCRIPTION_GRACE_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Subscription gate
    |--------------------------------------------------------------------------
    |
    | When enabled, App\Http\Middleware\EnsureSubscribed blocks every tenant
    | route (web + API + Livewire updates) once the hospital has no access-
    | granting subscription. Disabled in the test environment so unrelated
    | feature tests need not seed subscriptions; SubscriptionGateTest and
    | TenancyMiddlewareTest enable it explicitly.
    |
    */
    'enforce_subscription' => env('SUBSCRIPTION_GATE', true),

];
