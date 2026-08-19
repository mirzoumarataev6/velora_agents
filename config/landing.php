<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Landing page sections
    |--------------------------------------------------------------------------
    |
    | The pricing section is fully built (partials/landing/pricing.blade.php +
    | the landing.pricing lang keys in all locales) but hidden while pricing is
    | not public. Flip this on to restore it — the section, nav links and FAQ
    | stay consistent without code changes.
    |
    */
    'show_pricing' => env('LANDING_SHOW_PRICING', false),

];
