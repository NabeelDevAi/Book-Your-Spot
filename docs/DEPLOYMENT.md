# Venu365 — Deployment & Operations

Everything needed to run V1 on a single Linux server. Written for the pilot
setup described in the SRS: one city, desktop-only web, no payment provider.

---

## 1. Requirements

| Component | Version | Notes |
|---|---|---|
| PHP | 8.3+ | Extensions: `pdo_mysql`, `mbstring`, `openssl`, `gd`, `zip`, `pcntl` |
| MySQL | 8.0+ | InnoDB. Row-level locking is **required** — see §6 |
| Composer | 2.x | |

**Node is not required, at build time or runtime.** There is no bundler: CSS and
JS are served from `public/assets/` exactly as they are written, using native
`@import` and native ES modules. Deploying is `composer install` — there is
nothing to build.

`pcntl` is only used by the concurrency test suite, not by the application.

`intl` is **not** required: PKR formatting is handled by `App\Support\Money`
rather than `Number::currency()`, specifically so the app runs without it.

---

## 2. First install

```bash
git clone <repo> /var/www/html/book-your-spot
cd /var/www/html/book-your-spot

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.pk
APP_TIMEZONE=Asia/Karachi      # V1 assumes a single timezone — do not change

DB_DATABASE=bookyourspot
DB_USERNAME=bookyourspot
DB_PASSWORD=<strong password>

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

Then:

```bash
php artisan migrate --force
php artisan db:seed --class=GameSeeder    # master category list (FR-2.3)
php artisan db:seed --class=AdminSeeder   # creates the first admin
# No storage:link step: uploaded media is written straight into public/uploads
# (config/filesystems.php), not the storage/app/public symlink Laravel
# defaults to -- chosen because the target host does not reliably support
# symlinks. Just make sure public/uploads is writable by the web server user.

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> **Change the seeded admin password immediately.** `AdminSeeder` creates
> `admin@venu365.pk` with the password `password`. Admin accounts cannot be
> self-registered (FR-1.3), so this account is the only way in.

`DemoDataSeeder` is skipped automatically outside `local`/`testing` — it is
sample content, not production data.

### Permissions

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

---

## 3. Web server

Document root must be `public/`, never the project root.

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.pk;
    root /var/www/html/book-your-spot/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Uploaded venue photos are served from the storage symlink.
    location ~* \.(jpg|jpeg|png|webp|css|js|ico)$ {
        expires 30d;
        access_log off;
    }

    client_max_body_size 8M;   # must exceed booking.max_image_kilobytes
}
```

---

## 4. Scheduler (required)

Three commands drive the reservation lifecycle. **Without cron the platform is
broken in a way that is not obvious**: requests never expire, so customers wait
forever for an answer that will never come, and slots are never released.

```cron
* * * * * cd /var/www/html/book-your-spot && php artisan schedule:run >> /dev/null 2>&1
```

| Command | Frequency | What breaks without it |
|---|---|---|
| `reservations:expire` | every minute | FR-4.7 — unanswered requests never expire |
| `reservations:complete` | every minute | FR-4.10 — bookings stay `confirmed` forever |
| `reservations:remind` | every minute | FR-5.1 — no pre-booking reminders |

Verify with `php artisan schedule:list`.

`reservations:complete` has a **2-hour grace period** after the end time so an
owner can still record a no-show. Without it the sweep would race the owner and
silently overwrite a no-show they had not submitted yet.

---

## 5. Queue worker (required)

Notifications are queued. Without a worker they are written to the `jobs` table
and never delivered — and since V1 has **no email**, the in-app notification is
the only channel a user has (the FR-5.1 amendment). A stalled queue means an
owner never learns a booking was requested.

`/etc/supervisor/conf.d/bookyourspot-worker.conf`:

```ini
[program:bookyourspot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/book-your-spot/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/bookyourspot-worker.log
stopwaitsecs=3600
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start bookyourspot-worker:*
```

Monitor with `php artisan queue:failed`.

---

## 6. Why MySQL specifically

The no-double-booking guarantee (NFR-1) is enforced by a `SELECT … FOR UPDATE`
row lock on the `spots` row inside the approval transaction. MySQL has no
exclusion constraint, so this lock **is** the guarantee.

Two consequences:

- **Do not run this on SQLite.** It has no row-level locking, so the guarantee
  silently disappears while every test still passes.
- **Do not lower the isolation level below `REPEATABLE READ`** (the InnoDB
  default) without re-running `tests/Feature/Booking/ConcurrentApprovalTest.php`.

That test proves the lock by racing real OS processes. Run it after any change
to `ReservationService::approve()`:

```bash
php artisan test --filter=ConcurrentApprovalTest
```

---

## 7. Backups (NFR-4)

```cron
15 3 * * * /var/www/html/book-your-spot/scripts/backup-database.sh >> /var/log/bys-backup.log 2>&1
```

The script uses `--single-transaction` (consistent snapshot, no write lock, so a
late-night booking is never blocked), verifies the archive is not truncated, and
prunes anything older than 14 days.

Configure with `BYS_BACKUP_DIR` and `BYS_BACKUP_RETENTION_DAYS`.

**Test the restore, not just the backup.** An untested backup is a guess:

```bash
mysql -e "CREATE DATABASE bys_restore_test"
gunzip -c /var/backups/bookyourspot/bookyourspot-YYYYMMDD-HHMMSS.sql.gz \
  | mysql bys_restore_test
mysql -e "SELECT COUNT(*) FROM bys_restore_test.reservations"
mysql -e "DROP DATABASE bys_restore_test"
```

Venue photos and videos in `public/uploads` are **not** in the database dump —
back that directory up separately.

---

## 8. Deploying an update

```bash
cd /var/www/html/book-your-spot
php artisan down --render="errors::503"

git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force

php artisan config:cache && php artisan route:cache && php artisan view:cache
supervisorctl restart bookyourspot-worker:*   # workers hold stale code otherwise

php artisan up
```

Restarting the workers matters: a running `queue:work` keeps the old code in
memory and will happily process jobs with it.

---

## 9. Configuration worth knowing

All booking behaviour is in `config/booking.php`. The values that change how the
product feels:

| Key | Default | Effect |
|---|---|---|
| `response_lead_hours` | 12 | A pending request expires 12h before the slot |
| `response_window_hours` | 2 | Same-day requests give the owner 2h to answer |
| `cancellation_cutoff_hours` | 1 | Cancelling inside this is flagged as late |
| `max_pending_per_user` | 3 | Caps speculative requests (SRS 9.18) |
| `min_booking_lead_minutes` | 30 | Earliest a slot can be requested |
| `max_advance_days` | 30 | How far ahead customers can book |
| `no_show_warning_threshold` | 2 | No-shows before a customer is flagged to owners |

Run `php artisan config:cache` after editing.

---

## 10. Health checks

```bash
php artisan about                     # framework, drivers, cache state
php artisan schedule:list             # cron is wired
php artisan queue:failed              # should be empty
curl -f https://yourdomain.pk/up      # built-in health endpoint
```

Symptoms worth recognising:

| Symptom | Likely cause |
|---|---|
| Requests never expire; owners see a growing pending list | `schedule:run` cron missing |
| Nobody receives notifications | queue worker stopped |
| "Venue is not visible in search" despite approval | no **active** spot (SRS 9.17) |
| Images 404 | `public/uploads` missing or not writable by the web server |
| Times off by hours | `APP_TIMEZONE` not `Asia/Karachi` |

---

## 11. Known V1 limitations

Deliberate, agreed scope decisions — not defects:

- **No email or SMS.** In-app notification is the only channel. An owner who
  never logs in will not learn about a request until it expires. This is the
  most significant operational risk of the pilot and is worth revisiting before
  a wider rollout.
- **No self-serve password reset.** Users file a request; an Admin verifies them
  by phone and issues a temporary password (FR-1.5 amendment).
- **No verification gate.** FR-1.4 is waived — `email_verified_at` exists so the
  gate can be switched on the day a mail provider is added.
- **Desktop-only, fixed 1280px layout** (NFR-7). Deliberate: no media queries.
- **Single city, single timezone, PKR only.**
- **Availability search resolves in PHP** over a SQL-narrowed candidate set,
  because opening hours live in a JSON column with overnight ranges. Correct and
  fast at the stated scale (NFR-3); would need a materialised availability table
  an order of magnitude beyond it.
- **Index note:** the two scheduled sweeps select via a sibling index sharing the
  `status` prefix, examining a few thousand rows once a minute. Measured at 20k
  reservations and fine at pilot scale; if reservation volume grows past ~500k,
  add an index hint or reorder those composites. The approval overlap check —
  the query that guards NFR-1 — uses the intended index and is unaffected.
