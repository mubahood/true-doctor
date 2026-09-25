<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The demonstration hospital
    |--------------------------------------------------------------------------
    |
    | When this is on, /test-login exists and the public pages offer "Try the
    | demo". It is a deliberate switch rather than a side effect of APP_ENV,
    | because a live site wanting a public demo is a normal thing to want and
    | should not require pretending to be a development machine.
    |
    | WHAT TURNING THIS ON MEANS. Anyone on the internet can sign in to the
    | demonstration hospital with a password printed on the page. That is the
    | point. What makes it safe is everything below, not the password:
    |
    |   * Tenancy. Demo accounts belong to one hospital and the database scope
    |     stops them reading any other. This is the same mechanism that stops
    |     two real hospitals seeing each other, and it is tested.
    |   * No platform account. DemoSeeder refuses to touch the super admin
    |     outside local — see the guard there. The account that can see every
    |     tenant never has a published password and is never on the rail.
    |   * A nightly reset. Whatever visitors do to the demo, it is put back.
    |
    */

    'enabled' => (bool) env('DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Which hospital is the demonstration
    |--------------------------------------------------------------------------
    |
    | Its slug. Only this tenant is ever reset, so a reset can never reach a
    | paying hospital's records even if one were somehow seeded alongside.
    |
    */

    'hospital' => env('DEMO_HOSPITAL', 'general-hospital-a'),

    /*
    |--------------------------------------------------------------------------
    | Putting it back
    |--------------------------------------------------------------------------
    |
    | A public demo is a hospital that strangers change. Left alone it fills
    | with test patients called "aaa" and every bed ends up occupied, and the
    | next visitor sees a mess rather than a product.
    |
    | The reset wipes the demonstration tenant's records and seeds it again.
    | Off by default even when the demo is on, so switching the demo on cannot
    | by itself start something that deletes rows on a schedule.
    |
    */

    'reset' => [
        'enabled' => (bool) env('DEMO_RESET', false),
        'at' => env('DEMO_RESET_AT', '03:30'),
    ],

];
