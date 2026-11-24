<?php

declare(strict_types=1);

use App\Quoting\Markup;

return [

    /*
    |--------------------------------------------------------------------------
    | Markup
    |--------------------------------------------------------------------------
    |
    | Whole percent added to the Pixelforge cost to get the price a user pays.
    | Kept as an integer on purpose: money maths never touches floats.
    | Changing it affects only generations submitted afterwards.
    |
    */

    'markup_percent' => (int) env('BILLING_MARKUP_PERCENT', 25),

    /*
    |--------------------------------------------------------------------------
    | Credit packs
    |--------------------------------------------------------------------------
    |
    | Whole dollars a user can add to their balance. The payment is fake: this is a
    | demo, no money moves anywhere.
    |
    */

    'credit_packs' => [5, 10, 20],

];
