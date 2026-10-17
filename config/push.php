<?php

/*
|--------------------------------------------------------------------------
| Push delivery
|--------------------------------------------------------------------------
| `null` logs and drops: the default, needs no credentials, and is what a
| client's own server uses. `fcm` is Firebase Cloud Messaging (HTTP v1): set
| PUSH_DRIVER=fcm and FCM_CREDENTIALS to the Firebase service account's JSON
| file path, or the JSON itself (base64 is fine). Another provider is a class
| implementing App\Services\Push\PushTransport, listed under `drivers`.
*/
return [
    'driver' => env('PUSH_DRIVER', 'null'),

    'drivers' => [
        'null' => \App\Services\Push\NullPushTransport::class,
        'fcm' => \App\Services\Push\FcmPushTransport::class,
    ],

    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS'),
    ],
];
