<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Site\BookingController;
use App\Http\Controllers\Site\WalletController;
use App\Http\Controllers\Site\BookingHistoryController;
use App\Http\Controllers\Site\BusinessController;
use App\Http\Controllers\Site\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
| Guests browse, filter, open a venue and even reach the booking form with
| real prices and live availability. Only submitting a request needs an
| account (SRS 9.13) -- hiding prices behind a login wall would undermine the
| pricing transparency the platform exists to provide.
*/

Route::get('/', [SearchController::class, 'index'])->name('home');
Route::get('/venues/{business}', [BusinessController::class, 'show'])->name('businesses.show');

Route::get('/spots/{spot}/book', [BookingController::class, 'create'])->name('bookings.create');
Route::get('/spots/{spot}/slots', [BookingController::class, 'slots'])->name('bookings.slots');

/*
|--------------------------------------------------------------------------
| Customer routes
|--------------------------------------------------------------------------
| `role:user` is what enforces SRS 9.14 at the routing layer -- owners and
| admins cannot reach the booking endpoints at all. The engine refuses them
| too; both layers are deliberate.
*/

Route::middleware(['auth', 'role:user'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    // Wallet. Payments are simulated, so a top-up settles on submit -- there
    // is no card form, no redirect out and no callback to wait for.
    Route::get('/wallet', [WalletController::class, 'show'])->name('wallet.show');
    Route::post('/wallet/topup', [WalletController::class, 'topup'])
        ->middleware('throttle:topup')
        ->name('wallet.topup');

    Route::post('/spots/{spot}/book', [BookingController::class, 'store'])
        ->middleware('throttle:booking')
        ->name('bookings.store');

    Route::get('/bookings', [BookingHistoryController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{reservation}', [BookingController::class, 'show'])->name('bookings.show');
    Route::post('/bookings/{reservation}/cancel', [BookingHistoryController::class, 'cancel'])
        ->name('bookings.cancel');
});

/*
|--------------------------------------------------------------------------
| Shared authenticated routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    // The notification centre is shared by all three roles: with no email in
    // V1 this is the only place anyone learns anything (the FR-5.1 amendment).
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::get('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
