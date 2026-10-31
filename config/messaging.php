<?php

declare(strict_types=1);

return [

    /*
    | Delivery driver per external channel (in_app is always delivered immediately, inside the database).
    |   "log"   writes metadata only (no message text) to the Laravel log: for development and demos.
    |   "null"  silently marks the message as sent.
    |   a class name implementing App\Support\Messaging\ChannelDriver (e.g. your SMS gateway).
    */
    'drivers' => [
        'sms' => env('MESSAGING_SMS_DRIVER', 'log'),
        'whatsapp' => env('MESSAGING_WHATSAPP_DRIVER', 'log'),
        'email' => env('MESSAGING_EMAIL_DRIVER', 'log'),
    ],

    // A message that keeps failing is retried until this many attempts, then marked "failed".
    'max_attempts' => (int) env('MESSAGING_MAX_ATTEMPTS', 3),

    // External (SMS / WhatsApp / email) messages one institution may queue by hand per day: a stolen account cannot burn the SMS budget.
    'daily_limit' => (int) env('MESSAGING_DAILY_LIMIT', 5000),

    // Largest audience of one send request.
    'broadcast_limit' => (int) env('MESSAGING_BROADCAST_LIMIT', 2000),
];
