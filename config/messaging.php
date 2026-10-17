<?php

/*
|--------------------------------------------------------------------------
| Messages to patients (App\Services\Messaging)
|--------------------------------------------------------------------------
|
| `log` writes messages to the log and sends nothing: the default here, in
| testing and on any server without a gateway. A production server on `log`
| never lets a clinic turn messages on (MessagingSettings::available), so no
| clinic believes reminders are going out when they are not.
|
| Africa's Talking: set SMS_DRIVER=africastalking, AT_USERNAME, AT_API_KEY and,
| once approved, AT_SENDER_ID. Point the account's delivery-report callback at
| /gateway/sms/delivery/<SMS_WEBHOOK_TOKEN> and, for a two-way number, its
| incoming-message callback at /gateway/sms/inbound/<SMS_WEBHOOK_TOKEN>, then
| set SMS_REPLY_STOP=true so messages say "Reply STOP".
*/

return [
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'webhook_token' => env('SMS_WEBHOOK_TOKEN'),
        // Only true when the sender takes replies (a short code or long number, not a name).
        'reply_stop' => (bool) env('SMS_REPLY_STOP', false),
        'africastalking' => [
            'username' => env('AT_USERNAME'),
            'api_key' => env('AT_API_KEY'),
            'sender_id' => env('AT_SENDER_ID'),
            'sandbox' => env('AT_USERNAME') === 'sandbox',
        ],
    ],
];
