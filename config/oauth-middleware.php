<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MijnKantoor Oauth Middleware
    |--------------------------------------------------------------------------
    |
    */

    //API Url to MijnKantoor
    'api_url' => env('API_URL'),
    'client_id' => env('CLIENT_ID'),
    'client_secret' => env('CLIENT_SECRET'),

    'cache' => [
        'prefix' => 'mijnkantoor',
        'keys' => [
            'access_token' => 'access_token',
            'expires_in' => 'expires_in',
            'refresh_token' => 'refresh_token'
        ]
    ],

    //Local user model
    'user' => 'App\\User',

    'redirect_route' => 'user.redirect',

    //When successfully logged in redirect to
    'default_redirect' => ''
];