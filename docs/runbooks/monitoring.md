# Runbook: monitoring, backups and errors

What watches the online service, what it checks, and what to do when it shouts. Everything here runs on a standalone server too, except the off-site backups and the drill (a standalone copy has `standalone:backup`).

## 1. The checks

`php artisan td:check` prints every check with what it found; `--json` gives one document. Exit code 1 when anything fails.

| Check | Fails when | Warns when |
|---|---|---|
| database | it cannot be reached | it takes more than 500 ms |
| migrations | any has not run | |
| queue | a job has waited more than 10 minutes (database queue) | more than 200 waiting (other queues) |
| failed_jobs | | any job failed in the last 24 hours |
| scheduler | it never ran, or not for an hour | not for 5 minutes |
| scheduled_jobs | | a job failed three times in a row |
| backup | no key, no backup, or the last is more than 36 hours old | it never left the server |
| restore_drill | the last drill failed | none yet, more than 40 days ago, or read-only |
| disk | less than 200 MB free | less than 1 GB |
| storage | a folder is not writable | |
| mail | production mail goes to `log` or `array` | the from-address is a no-reply one |
| payments | | Pesapal is in sandbox in production |
| licence (standalone) | | missing or invalid |
| security (production) | debug on, sessions unencrypted, insecure cookie on https | |

`GET /health` answers a monitor with the overall status and each check's status only (no details), **503** when anything fails. It is cached for 30 seconds and limited to 60 requests a minute.

## 2. The uptime monitor and status page

Set up once, outside the code (Better Stack, UptimeRobot or similar, free tiers are enough):

1. A monitor on `https://true-doctor.online/up` every minute (the app answers 200 when it boots).
2. A monitor on `https://true-doctor.online/health` every 5 minutes, alerting on any status other than 200.
3. Alerts to the owner by email and by WhatsApp or SMS (the monitor's own integrations).
4. The monitor's public status page. Put its address in `STATUS_PAGE_URL`: the site's footer, the app's help card and `meta.support.status_url` then link to it.

## 3. The scheduler

The live host's cron runs every 19 minutes. Jobs no longer depend on landing on an exact minute: each daily or weekly job runs at the first tick after its time and a missed day is caught up (`App\Support\Scheduling\ScheduledRun`, table `scheduled_runs`). Still, reminders and the mail queue want a tick every minute: when the host allows it, set the cron to every minute. `td:check` warns while ticks are more than 5 minutes apart.

## 4. Backups (online service)

- Nightly at 01:30 (`td:backup`): the database, `.env`, the licence files and uploaded documents in one zip, encrypted with `BACKUP_ENCRYPTION_KEY`, sent to S3-compatible storage (`BACKUP_S3_*`), 30 daily and 12 monthly copies kept. The unencrypted zip never stays on the server; the last three encrypted copies do.
- **The key.** `php artisan td:backup --new-key` prints one. Keep a copy offline (with the release signing key backup). Without it, no backup can be opened by anybody, including us.
- Monthly on the 2nd at 05:00 (`td:backup-drill`): the newest backup is downloaded, decrypted, unpacked and restored into the scratch database (`BACKUP_DRILL_DB_*`), compared with live (every table present, row counts close, the oldest rows identical), then the scratch database is emptied. Without a scratch database it is read end to end but not restored, and `td:check` says so.

### Restoring for real

1. Put the site in maintenance: `php artisan down`.
2. Download the backup from the store (or take one from `storage/app/private/backups/offsite`).
3. Decrypt: `php artisan tinker` then `App\Support\Backups\BackupCipher::decrypt('in.tdbak', 'out.zip', base64_decode('KEY'))`.
4. Unzip; restore `database.sql.gz` with `gunzip < database.sql.gz | mysql -u USER -p DATABASE` (or phpMyAdmin); copy `files/` back under `storage/`; compare `settings/.env` with the live `.env` (the `APP_KEY` must match the backup's, or encrypted fields cannot be read).
5. `php artisan migrate --force`, `php artisan td:check`, `php artisan up`.

## 5. Errors

Every error is kept in `error_events`, one row per kind with a count, its personal data scrubbed (emails, phones, long numbers, tokens and quoted values are removed before anything is stored). The error page and the log show a 6-letter reference; Super → Errors finds it. The app sends the crashes it recorded (`POST /api/v1/diagnostics/errors`). With `ERROR_TRACKING_DSN` set, errors are also sent to Sentry or a self-hosted GlitchTip, with user and hospital as ids only.
