<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Invalid forms are redirected with previous input in the session.
        // Never retain secrets from optional integrations in that input.
        $exceptions->dontFlash([
            'telegram_api_id', 'telegram_api_hash',
            'nextcloud_password', 'github_token', 'kimai_token',
            'api_token', 'api_key', 'api_secret',
            'password', '_token',
        ]);
    })->create();
