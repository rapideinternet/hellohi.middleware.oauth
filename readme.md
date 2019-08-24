# OAuth2 MijnKantoor middleware for Laravel 5

## Installation

Require the `mijnkantoor/middleware.oauth` package in your `composer.json` and update your dependencies:
```sh
$ composer require mijnkantoor/middleware.oauth
```

For laravel >=5.5 that's all. This package supports Laravel new [Package Discovery](https://laravel.com/docs/5.5/packages#package-discovery).

If you are using Laravel < 5.5 or have package discovery disabled, you also need to add OauthMiddleware\ServiceProvider to your `config/app.php` providers array:
```php
MijnKantoor\OauthMiddleware\ServiceProvider::class,
```

## Global usage

To allow OAuth for all your routes, add the `ValidateOAuth` middleware in the `$middleware` property of  `app/Http/Kernel.php` class:

```php
protected $middleware = [
    // ...
    \MijnKantoor\OauthMiddleware\ValidateOAuth::class,
];
```

## Group middleware

If you want to allow OAuth on a specific middleware group or route, add the `ValidateOAuth` middleware to your group:

```php
protected $middlewareGroups = [
    'web' => [
       // ...
    ],

    'api' => [
        // ...
        \MijnKantoor\OauthMiddleware\ValidateOAuth::class,
    ],
];
```

## Configuration

The defaults are set in `config/oauth-middleware.php`. Copy this file to your own config directory to modify the values. You can publish the config using this command:
```sh
$ php artisan vendor:publish --provider="MijnKantoor\OAuthMiddleware\ServiceProvider"
```

