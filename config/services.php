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

    /*
    |--------------------------------------------------------------------------
    | OCR Service — Python microservice for document understanding
    |--------------------------------------------------------------------------
    |
    | The OCR service runs a FastAPI server that uses Tesseract with image
    | preprocessing and Ollama (local LLM) for document understanding.
    |
    | Start the service with: python ocr-service/app.py
    |
    */
    'ocr' => [
        'base_url' => env('OCR_SERVICE_URL', 'http://127.0.0.1:5050'),
        'ollama_model' => env('OCR_OLLAMA_MODEL', 'qwen2.5:7b'),
        'timeout' => env('OCR_SERVICE_TIMEOUT', 120),
    ],

];
