<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The two consoles live in their own route files. Role enforcement
            // is declared inside each file so a route cannot be added to the
            // owner console and accidentally inherit no role check.
            Route::middleware('web')->group(base_path('routes/owner.php'));
            Route::middleware('web')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'active' => EnsureAccountActive::class,
            'password.changed' => RequirePasswordChange::class,
        ]);

        // Appended to the whole web group rather than to individual route
        // files: a suspension or a forced password change has to take effect
        // everywhere at once, including the public pages, not only in the
        // consoles. Both middleware no-op for guests.
        $middleware->web(append: [
            EnsureAccountActive::class,
            RequirePasswordChange::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
