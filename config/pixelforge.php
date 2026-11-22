<?php

declare(strict_types=1);

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    // One API key per environment: each key has its own webhook URL and signing secret.
    'api_key' => env('PIXELFORGE_API_KEY'),

    // The rates and token rules of GET /v1/pricing are reused for this long. The request limit is shared
    // by the whole account, so pricing must not be fetched for every estimate.
    'pricing_fresh_seconds' => (int) env('PIXELFORGE_PRICING_FRESH_SECONDS', 300),

    // After that the old pricing is still served for this long while one request refreshes it, so a
    // Pixelforge outage does not stop estimates and submissions at once.
    'pricing_stale_seconds' => (int) env('PIXELFORGE_PRICING_STALE_SECONDS', 3600),

    // Pixelforge allows this many generations queued or processing at once, for the whole account.
    'max_concurrent_generations' => (int) env('PIXELFORGE_MAX_CONCURRENT_GENERATIONS', 3),

    // A user can have this many generations waiting or running at once. Without balances (stage 1 of
    // the product) this is what stops one user from filling the queue.
    'max_unfinished_generations_per_user' => (int) env('PIXELFORGE_MAX_UNFINISHED_PER_USER', 5),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Request limits
    |--------------------------------------------------------------------------
    |
    | Limits of POST /v1/generations that the pricing endpoint does not expose.
    | Values from the Pixelforge docs; override here if the provider changes them.
    |
    */

    'limits' => [
        'max_prompt_characters' => (int) env('PIXELFORGE_MAX_PROMPT_CHARACTERS', 4000),
        'max_reference_images' => (int) env('PIXELFORGE_MAX_REFERENCE_IMAGES', 4),
    ],

];
