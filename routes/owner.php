<?php

use App\Http\Controllers\Owner\BusinessController;
use App\Http\Controllers\Owner\ConflictController;
use App\Http\Controllers\Owner\BusinessGameController;
use App\Http\Controllers\Owner\DashboardController;
use App\Http\Controllers\Owner\ImageController;
use App\Http\Controllers\Owner\ReservationController;
use App\Http\Controllers\Owner\SpotBlockController;
use App\Http\Controllers\Owner\SpotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Owner console
|--------------------------------------------------------------------------
| Every route is guarded by `role:owner` (FR-1.6). Ownership of the specific
| Business is then checked by policy inside each controller action -- the role
| check alone would let one owner reach another owner's venue.
*/

Route::middleware(['auth', 'role:owner'])
    ->prefix('owner')
    ->name('owner.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // FR-2.7 / FR-4.6 -- the daily queue and responding to requests.
        Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::get('reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
        Route::post('reservations/{reservation}/approve', [ReservationController::class, 'approve'])->name('reservations.approve');
        Route::post('reservations/{reservation}/reject', [ReservationController::class, 'reject'])->name('reservations.reject');
        Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
        Route::post('reservations/{reservation}/no-show', [ReservationController::class, 'noShow'])->name('reservations.no-show');

        // SRS 9.6 -- bookings disrupted by a block or a deactivation.
        Route::get('conflicts', [ConflictController::class, 'index'])->name('conflicts.index');
        Route::post('conflicts/{conflict}/resolve', [ConflictController::class, 'resolve'])->name('conflicts.resolve');

        // FR-2.1 / FR-2.2 -- venue profile.
        Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
        Route::get('businesses/create', [BusinessController::class, 'create'])->name('businesses.create');
        Route::post('businesses', [BusinessController::class, 'store'])->name('businesses.store');

        Route::prefix('businesses/{business}')->name('businesses.')->group(function () {
            Route::get('edit', [BusinessController::class, 'edit'])->name('edit');
            Route::put('/', [BusinessController::class, 'update'])->name('update');
            Route::delete('/', [BusinessController::class, 'destroy'])->name('destroy');

            Route::delete('images/{image}', [ImageController::class, 'destroyBusinessImage'])
                ->name('images.destroy');

            // FR-2.3 -- pick categories from the Admin-managed master list.
            Route::get('games', [BusinessGameController::class, 'edit'])->name('games.edit');
            Route::put('games', [BusinessGameController::class, 'update'])->name('games.update');

            // FR-2.4 -- bookable units.
            Route::get('spots', [SpotController::class, 'index'])->name('spots.index');
            Route::get('spots/create', [SpotController::class, 'create'])->name('spots.create');
            Route::post('spots', [SpotController::class, 'store'])->name('spots.store');

            Route::prefix('spots/{spot}')->name('spots.')->group(function () {
                Route::get('edit', [SpotController::class, 'edit'])->name('edit');
                Route::put('/', [SpotController::class, 'update'])->name('update');
                Route::delete('/', [SpotController::class, 'destroy'])->name('destroy');

                // SRS 9.9 -- deactivation is the normal path; deletion is the
                // rare one, permitted only with no reservation history.
                Route::post('deactivate', [SpotController::class, 'deactivate'])->name('deactivate');
                Route::post('activate', [SpotController::class, 'activate'])->name('activate');

                Route::delete('images/{image}', [ImageController::class, 'destroySpotImage'])
                    ->name('images.destroy');

                // FR-2.9 -- ad hoc downtime.
                Route::get('blocks', [SpotBlockController::class, 'index'])->name('blocks.index');
                Route::post('blocks', [SpotBlockController::class, 'store'])->name('blocks.store');
                Route::delete('blocks/{block}', [SpotBlockController::class, 'destroy'])->name('blocks.destroy');
            });
        });
    });
