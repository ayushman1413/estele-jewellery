<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shiprocket' => [
        'email' => env('SHIPROCKET_EMAIL'),
        'password' => env('SHIPROCKET_PASSWORD'),
        'pickup_pincode' => env('SHIPROCKET_PICKUP_PINCODE'),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    // Same VAS Multimedia bulk-SMS gateway (vas.themultimedia.in) already
    // used by the user's other project (ZappDeal) for OTP delivery — env
    // var names deliberately match ZappDeal's own .env one-for-one so
    // credentials can be copy-pasted between the two projects once obtained.
    // See App\Services\Otp\VasMultimediaOtpGateway.
    'vas_sms' => [
        'api_key' => env('VAS_SMS_API_KEY'),
        'sender' => env('VAS_SMS_SENDER', 'TCONGS'),
        'entity_id' => env('VAS_SMS_ENTITY_ID'),
        'template_id' => env('VAS_SMS_TEMPLATE_ID'),
        'template' => env('VAS_SMS_MESSAGE_TEMPLATE', 'Your Estele OTP is %s'),
        'ca_bundle' => env('SMS_CA_BUNDLE'),
    ],

    // Explicit gateway pick for OtpManager (see that class) — 'vas_multimedia'
    // or 'twilio'. Falls back to auto-detecting whichever configured gateway
    // comes first if unset, so this is optional, not required.
    'sms' => [
        'driver' => env('SMS_DRIVER'),
    ],

    // WhatsApp Business API provider for vendor/customer old-jewellery
    // notifications. Leave WHATSAPP_PROVIDER unset to stay on LogWhatsAppGateway
    // (messages logged, not sent) — see App\Services\WhatsApp\LogWhatsAppGateway.
    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER'),
        'api_key' => env('WHATSAPP_API_KEY'),
        'api_url' => env('WHATSAPP_API_URL'),
        'from_number' => env('WHATSAPP_FROM_NUMBER'),
    ],

    // Alternative OTP gateway (App\Services\Otp\TwilioOtpGateway) — added
    // alongside VAS Multimedia after VAS's real submissions kept coming back
    // "SUCCESS" from the API but never actually reaching a phone across
    // three separate live test sends, which pointed at the VAS account
    // itself (likely still in trial/test mode) rather than our integration.
    // SMS_DRIVER picks which configured gateway OtpManager actually uses;
    // see that class for the selection order.
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_PHONE_NUMBER'),
    ],

];
