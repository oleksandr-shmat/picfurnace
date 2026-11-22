<?php

declare(strict_types=1);

return [

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
