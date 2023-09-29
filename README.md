
<p align="center">
<a href="https://github.com/AlAdler/LaravelPennantSessionAndDBDriver/actions"><img src="https://github.com/AlAdler/LaravelPennantSessionAndDBDriver/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/aladler/laravel-pennant-session-and-db-driver"><img src="https://img.shields.io/packagist/dt/AlAdler/Laravel-Pennant-Session-And-DB-Driver" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/aladler/laravel-pennant-session-and-db-driver"><img src="https://img.shields.io/packagist/v/AlAdler/Laravel-Pennant-Session-And-DB-Driver" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/aladler/laravel-pennant-session-and-db-driver"><img src="https://img.shields.io/packagist/l/AlAdler/Laravel-Pennant-Session-And-DB-Driver" alt="License"></a>
</p>

## Introduction

A 'session & DB driver' for Laravel Pennant for feature flags pre- and post-user authentication.

## Requirements

- Laravel 10 or higher
- PHP 8.1 or higher
- [Pennant 1.5](https://laravel.com/docs/10.x/pennant) or higher

## Installation

You can install the package via composer:

```bash
composer require aladler/laravel-pennant-session-and-db-driver
```

Add the driver to your `config/pennant.php` file:

```php
'stores' => [

    'session_and_database' => [
        'driver' => 'session_and_database',
        'table' => 'features',
    ],

],
```

Register the driver using Pennant's `extend` method (this can be done in the `AppServiceProvider`'s `boot` method)

```php
public function boot(): void
{
    Feature::extend('session_and_database', function (){
        return new SessionAndDatabaseDriver(
            app()['db']->connection(),
            app()['events'],
            config(),
            []
        );
    });
}
```

If you wish this driver to be the default driver, change the `default` value in `config/pennant.php` to `session_and_database`.

```php
'default' => env('PENNANT_STORE', 'session_and_database'),
```

or put it in your .env file
    
```
PENNANT_STORE=session_and_database
```

Your User model (or any other Authenticatable) must implement the `Aladler\LaravelPennantSessionAndDbDriver\Contracts\UserThatHasPreRegisterFeatures` interface.

```php
class User extends Authenticable implements UserThatHasPreRegisterFeatures
```

## Usage

You can activate features for guests and after authentication, the feature will be persisted in the database.
Or if a feature is activated when a user is logged in, if they log out (or the session times out in the same device), the feature will still be active for them.
This allows, for example, to a/b tests features on the registration flow and keep the same experience after registration is completed.

## License

This open-sourced software is licensed under the [MIT license](LICENSE.md).
