<?php

/*
|--------------------------------------------------------------------------
| Who to contact for help
|--------------------------------------------------------------------------
|
| The fallbacks used when the super admin has not set a value in
| Settings → Site. Nothing outside this file, .env.example and the docs may
| type the number: everything reads App\Support\SupportContact, and a CI scan
| fails the build if the digits appear anywhere else.
*/

return [

    'whatsapp' => env('SUPPORT_WHATSAPP', '256783204665'),
    'phone' => env('SUPPORT_PHONE', '+256 783 204 665'),
    'email' => env('SUPPORT_EMAIL', 'support@true-doctor.online'),
    'hours' => env('SUPPORT_HOURS', 'Monday to Saturday, 8:00 to 20:00'),

    /*
    | Whether patient documents carry a small "System by True-Doctor" line.
    | On for the online service, off for a standalone copy (the buyer's brand
    | stays theirs); a hospital can switch it in Settings → Documents.
    */
    'document_footer_saas' => true,
    'document_footer_standalone' => false,
];
