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
