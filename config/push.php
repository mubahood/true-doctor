<?php

/*
|--------------------------------------------------------------------------
| Push delivery
|--------------------------------------------------------------------------
| `null` logs and drops: the default, needs no credentials, and is what a
| client's own server uses. Adding FCM later is a class implementing
| App\Services\Push\PushTransport, listed under `drivers`, and PUSH_DRIVER=fcm.
*/
return [
    'driver' => env('PUSH_DRIVER', 'null'),

    'drivers' => [
        'null' => \App\Services\Push\NullPushTransport::class,
    ],
];
