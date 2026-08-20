<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active application brand
    |--------------------------------------------------------------------------
    |
    | Each subdirectory of `path` is a complete brand pack (icons, theme,
    | public page copy, notification artwork). Switch packs with APP_BRAND.
    | Unknown slugs fall back to `default`.
    |
    */

    'path' => base_path('branding'),

    'default' => 'fundflow',

    'active' => env('APP_BRAND', 'fundflow'),

];
