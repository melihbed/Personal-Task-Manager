<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/integrations/google/callback'),
    ],

    // A local Ollama server. The assistant runs entirely on this machine.
    'ollama' => [
        'url' => rtrim((string) env('OLLAMA_URL', 'http://localhost:11434'), '/'),
        'model' => env('OLLAMA_MODEL', 'gpt-oss:latest'),
        // How hard a reasoning model (gpt-oss) thinks before answering: low, medium or high. Models without reasoning ignore it.
        'think' => env('OLLAMA_THINK', 'low'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 180),
        // How long Ollama keeps the model in memory after an answer. Loading a large model can take a minute, so it stays ready.
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
