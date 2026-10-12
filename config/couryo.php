<?php

return [

    /*
    | API key (ck_live_... or ck_test_...). Falls back to config('services.couryo.key').
    */
    'api_key' => env('COURYO_API_KEY'),

    'base_url' => env('COURYO_BASE_URL', 'https://api.couryo.com/v1'),

    /*
    | Secret (whsec_...) shown when the webhook was created, for Couryo\Webhook::verify().
    */
    'webhook_secret' => env('COURYO_WEBHOOK_SECRET'),

    /*
    | Language of the API error messages: pt-BR or en.
    */
    'language' => env('COURYO_LANGUAGE', 'pt-BR'),

    'timeout' => (float) env('COURYO_TIMEOUT', 30),

    'max_retries' => (int) env('COURYO_MAX_RETRIES', 2),

];
