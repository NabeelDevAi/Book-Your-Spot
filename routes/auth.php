<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\ForcedPasswordController;
use App\Http\Controllers\Auth\PasswordAssistanceController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    /*
     | Password assistance replaces Breeze's emailed reset link. V1 delivers no
     | email, so a link would go nowhere -- instead this files a request for an
     | Admin to action. Laravel's `password_reset_tokens` table and mail
     | notification remain untouched, so the standard flow can be restored by
     | swapping these three routes back once a mail provider exists.
     */
    Route::get('forgot-password', [PasswordAssistanceController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordAssistanceController::class, 'store'])
        ->middleware('throttle:password-assistance')
        ->name('password.email');

    Route::get('password-requested', [PasswordAssistanceController::class, 'requested'])
        ->name('password.requested');
});

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    // Reachable while must_change_password is set -- see RequirePasswordChange.
    Route::get('change-password', [ForcedPasswordController::class, 'edit'])
        ->name('password.change');
    Route::put('change-password', [ForcedPasswordController::class, 'update'])
        ->name('password.change.update');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
