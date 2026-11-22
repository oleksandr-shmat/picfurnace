<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Copies of generated images
    |--------------------------------------------------------------------------
    |
    | Pixelforge links stop working 24 hours after a generation completes, so we keep our own copy
    | on this disk and delete it after the retention period. Users are shown the date.
    |
    */

    'disk' => env('IMAGE_DISK', env('FILESYSTEM_DISK', 'local')),

    'retention_days' => (int) env('IMAGE_RETENTION_DAYS', 14),

];
