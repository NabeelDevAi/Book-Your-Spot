<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Requires a single system cron entry:
|
|   * * * * * cd /var/www/html/book-your-spot && php artisan schedule:run >> /dev/null 2>&1
|
| Everything runs every minute. Expiry is time-sensitive by definition -- a
| deadline that passes at 8:00 should release the slot at 8:00, not at the top
| of the next hour -- and each command is a single indexed query that finds
| nothing the vast majority of the time.
|
| withoutOverlapping() matters because notifications are queued: if the queue
| backs up, a slow run must not have a second run start alongside it and
| double-notify.
*/

Schedule::command('reservations:expire')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('reservations:complete')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('reservations:remind')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Earnings maturity. Every minute like the other booking sweeps: an owner
| watching their balance after a dispute window closes should see it move then,
| not at the top of the next hour. The query is a single indexed lookup that
| finds nothing almost every time.
*/
Schedule::command('wallet:mature')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Top-up reconciliation runs hourly rather than every minute: it makes an
| outbound API call per stale row, and a dropped webhook is rare. The default
| 30-minute staleness threshold means anything it finds is already well past
| the point where a webhook should have arrived.
*/
Schedule::command('topups:reconcile')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
