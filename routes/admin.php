<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GameController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\PasswordResetRequestController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin console
|--------------------------------------------------------------------------
| Guarded by `role:admin` (FR-1.6). Admin accounts are never self-registerable
| (FR-1.3) -- they come from the seeder or direct DB access only.
*/

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // FR-3.7 / section 8.
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

        // FR-3.1 / FR-3.2 / FR-3.6 / FR-3.8 -- venue moderation.
        Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
        Route::get('businesses/create', [BusinessController::class, 'create'])->name('businesses.create');
        Route::post('businesses', [BusinessController::class, 'store'])->name('businesses.store');

        Route::prefix('businesses/{business}')->name('businesses.')->group(function () {
            Route::get('/', [BusinessController::class, 'show'])->name('show');
            Route::get('edit', [BusinessController::class, 'edit'])->name('edit');
            Route::put('/', [BusinessController::class, 'update'])->name('update');

            Route::post('approve', [BusinessController::class, 'approve'])->name('approve');
            Route::post('reject', [BusinessController::class, 'reject'])->name('reject');
            Route::post('suspend', [BusinessController::class, 'suspend'])->name('suspend');
            Route::post('reinstate', [BusinessController::class, 'reinstate'])->name('reinstate');
            Route::post('clear-duplicate', [BusinessController::class, 'clearDuplicateFlag'])->name('clear-duplicate');
        });

        // Pricing amendment -- the platform-wide holiday calendar.
        Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');

        // FR-3.3 -- the master category list.
        Route::get('games', [GameController::class, 'index'])->name('games.index');
        Route::post('games', [GameController::class, 'store'])->name('games.store');
        Route::put('games/{game}', [GameController::class, 'update'])->name('games.update');
        Route::post('games/{game}/deactivate', [GameController::class, 'deactivate'])->name('games.deactivate');
        Route::post('games/{game}/activate', [GameController::class, 'activate'])->name('games.activate');

        // FR-3.5 -- every reservation on the platform.
        Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::get('reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');

        // FR-3.4 -- account moderation.
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
        Route::post('users/{user}/reinstate', [UserController::class, 'reinstate'])->name('users.reinstate');

        // Owner-account approval gate -- an Owner cannot log in until one of these fires.
        Route::post('users/{user}/approve-owner', [UserController::class, 'approveOwner'])->name('users.approve-owner');
        Route::post('users/{user}/reject-owner', [UserController::class, 'rejectOwner'])->name('users.reject-owner');

        // Stands in for the emailed reset link -- see the FR-1.5 amendment.
        Route::get('password-requests', [PasswordResetRequestController::class, 'index'])
            ->name('password-requests.index');
        Route::post('password-requests/{passwordResetRequest}/issue', [PasswordResetRequestController::class, 'issue'])
            ->name('password-requests.issue');
        Route::post('password-requests/{passwordResetRequest}/dismiss', [PasswordResetRequestController::class, 'dismiss'])
            ->name('password-requests.dismiss');

        // NFR-6 / SRS 9.20 -- read-only moderation trail.
        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

        // Web-reachable optimize/cache-clear -- no shell access on every deploy.
        Route::get('system', [SystemController::class, 'index'])->name('system.index');
        Route::post('system/optimize', [SystemController::class, 'optimize'])->name('system.optimize');
        Route::post('system/clear-cache', [SystemController::class, 'clearCache'])->name('system.clear-cache');
    });
