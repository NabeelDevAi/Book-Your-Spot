<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Use our hand-written paginator markup rather than Laravel's
        // Tailwind default.
        Paginator::defaultView('vendor.pagination.default');
        Paginator::defaultSimpleView('vendor.pagination.default');

        // Fail loudly on N+1 queries and on mass-assignment silently dropping an
        // attribute -- the booking engine depends on attributes actually being
        // set, and a status change quietly discarded because the column is not
        // fillable is exactly the kind of bug that otherwise reaches production.
        //
        // Enabled in testing as well as local: a test that "passes" because an
        // update was silently ignored is worse than no test at all.
        //
        // `preventAccessingMissingAttributes` is deliberately left off: Blade
        // legitimately reads nullable columns on partially-loaded models.
        $strict = $this->app->environment('local', 'testing');

        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);

        // preventLazyLoading() alone has a gap worth closing. Eloquent only arms
        // the guard on models hydrated from a result set of more than one row
        // (Builder::hydrate -- `if (count($items) > 1)`), so a route-model-bound
        // spot, a find(), or a first() lazy-loads in silence. That is how a
        // missing eager load on the venue page reached a browser instead of a
        // test: the controller's own fetches were exempt, and the violation only
        // surfaced once a venue happened to have two spots.
        //
        // Arming every retrieved model makes the guard mean what it says.
        if ($strict) {
            Event::listen('eloquent.retrieved: *', function (string $event, array $models): void {
                foreach ($models as $model) {
                    if ($model instanceof Model) {
                        $model->preventsLazyLoading = true;
                    }
                }
            });
        }

        $this->registerAdminOverride();
        $this->registerRateLimiters();
        $this->registerThemeDirective();
    }

    /**
     * `@themeAttribute` -- renders data-theme onto <html> from the cookie.
     *
     * Server-rendering the choice is what makes the theme switch flash-free.
     * The usual approach is an inline blocking script in <head>, but that only
     * exists because the preference is trapped in localStorage where the server
     * cannot see it. In a cookie, Blade can emit the attribute before a byte of
     * CSS is parsed -- nothing to sequence, and it still works with JS off.
     *
     * Absent or unrecognised means "follow the OS", which the stylesheet
     * handles via `color-scheme: light dark`. The whitelist matters: the cookie
     * is written by client-side JS and excluded from encryption, so it must be
     * treated as untrusted input rather than interpolated into markup.
     */
    private function registerThemeDirective(): void
    {
        // Emitted as one line: a multi-line PHP block inside a directive would
        // put its own newlines and indentation into the markup.
        Blade::directive('themeAttribute', fn () => "<?php echo in_array(request()->cookie('theme'), ['light', 'dark'], true) ? ' data-theme=\"'.request()->cookie('theme').'\"' : ''; ?>");
    }

    /**
     * FR-3.6: Admin has override access to every Business, Spot and Reservation.
     *
     * Centralised here so no individual policy has to remember an `isAdmin()`
     * branch -- a single forgotten check would otherwise silently deny Admin a
     * moderation action, or worse, be written as `|| $user->isAdmin()` in some
     * places and not others.
     *
     * Returning null (rather than false) for non-admins is essential: it means
     * "no opinion, carry on to the policy", whereas false would short-circuit
     * every check in the application to denied.
     */
    private function registerAdminOverride(): void
    {
        Gate::before(function (User $user, string $ability) {
            return $user->isAdmin() ? true : null;
        });
    }

    /**
     * NFR-2. Registration and password-assistance are limited by IP: both are
     * unauthenticated and both create rows, so they are the obvious targets for
     * scripted abuse. Login has its own per-credential limiter in LoginRequest.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        RateLimiter::for('password-assistance', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // Booking is capped per account rather than per IP: several customers
        // may legitimately share a venue's wifi, and the pending cap (SRS 9.18)
        // already limits speculation. This is a defence against scripted
        // submission, not against enthusiasm.
        RateLimiter::for('booking', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
    }
}
