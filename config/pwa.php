<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Would you like the install button to appear on all pages?
      Set true/false
    |--------------------------------------------------------------------------
    */

    'install-button' => true,

    /*
    |--------------------------------------------------------------------------
    | PWA Manifest Configuration
    |--------------------------------------------------------------------------
    |  php artisan erag:update-manifest
    */

    'manifest' => [
        'name' => 'Dashboard SDM BBPMP Jatim',
        'short_name' => 'SDM BBPMP',
        'background_color' => '#ffffff',
        'display' => 'standalone',
        'description' => 'Monitoring DUK & Peta Jabatan BBPMP Jawa Timur',
        'theme_color' => '#1d4ed8',
        'icons' => [
            [
            'src' => 'logo.png', // Syarat: harus ada file logo.png (512x512px) di folder public/
            'sizes' => '512x512',
            'type' => 'image/png',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Configuration
    |--------------------------------------------------------------------------
    | Toggles the application's debug mode based on the environment variable
    */

    'debug' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Livewire Integration
    |--------------------------------------------------------------------------
    | Set to true if you're using Livewire in your application to enable
    | Livewire-specific PWA optimizations or features.
    */

    'livewire-app' => false,
];
