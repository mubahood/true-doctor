# Notifications (Phase 4, Step 19)

Real, queued notifications for the three events the plan calls out — low stock, lab-result
ready, appointment reminders — over in-app (database) + SMS channels, with a push device-token
registry. Not stubs.

## Files

| Concern | File |
|---|---|
| Tables | `2026_07_19_280000_create_notifications_table.php` (database channel), `…_280010_create_device_tokens_table.php` |
| Model | `app/Models/DeviceToken.php` |
| SMS channel | `app/Notifications/Channels/SmsChannel.php` (wraps the swappable `VerificationChannel`) |
| Notifications | `app/Notifications/{LowStockAlert,LabResultReady,AppointmentReminder}.php` (all `ShouldQueue`) |
| Reminder command | `app/Console/Commands/SendAppointmentReminders.php` (`appointments:remind`) |
| Controllers | `app/Http/Controllers/Admin/{Notification,DeviceToken}Controller.php` |
| Views | `resources/views/admin/notifications/index.blade.php` + sidebar bell with unread count |

## Channels

Each notification is **queued** (`ShouldQueue`) and goes via `['database', SmsChannel::class]`.
`SmsChannel` sends through the app's existing swappable SMS transport (`VerificationChannel` —
`LogChannel` by default, a live gateway in production), so no vendor lock-in. A mail method is
also provided where useful. Class-name channels are container-resolved, so `SmsChannel`'s
transport is injected automatically. Push **device tokens** are stored per user
(`POST /admin/device-tokens`) ready for an FCM/APNs sender.

## Triggers (wired, tested)

- **Low stock** — `StockService` fires `LowStockAlert` to the hospital's pharmacists + admins
  **only when a movement crosses** from above the reorder level to at/below it (no repeat spam).
- **Lab result ready** — `LabService` notifies the **ordering doctor** when an order reaches
  `completed`.
- **Appointment reminder** — `appointments:remind` (scheduled daily 07:00) notifies the doctor
  in-app + SMS and SMSs the patient (who has no login) directly, for the target date's
  scheduled/confirmed appointments.

## In-app

The sidebar shows a **bell with the unread count**; `/admin/notifications` lists the staff
member's database notifications with mark-read / mark-all-read.

## Tests

`NotificationTest` (low-stock alert only on crossing reorder, none while above; lab-completion
notifies the doctor; `appointments:remind` notifies the doctor; device-token registration) — via
`Notification::fake()`.

## Sending mail

Every notification in `app/Notifications` implements `ShouldQueue`, and a test
holds them to it (`EmailVerificationResendTest`). That is not a performance
choice: a notification that sends inside the request turns a mail provider
having a bad day into a 500 and a stack trace for whoever clicked the button.
Laravel's own `VerifyEmail` and `ResetPassword` are *not* queued by default, so
both are subclassed — `VerifyEmailNotification`, `ResetPasswordNotification` —
and `User` overrides `sendEmailVerificationNotification()` to use ours.

**A queue worker must be running** or nothing is ever delivered. See
[`deployment.md`](deployment.md); Horizon is the worker.

### Configuration

```dotenv
MAIL_MAILER=log        # locally: messages go to storage/logs/laravel.log
MAIL_SCHEME=tls        # 587 = STARTTLS ("tls") · 465 = implicit TLS ("smtps")
```

Two traps, both of which have already cost time here:

- **`MAIL_ENCRYPTION` does nothing.** Laravel 11+ reads `MAIL_SCHEME`;
  `config/mail.php` has no `encryption` key at all. An `.env` carried over from
  an older release can carry `MAIL_ENCRYPTION=ssl` next to port 587 and look
  configured while being entirely ignored.
- **Gmail refuses ordinary account passwords** with
  `535-5.7.8 Username and Password not accepted`. It has done since May 2022.
  Turn on 2-step verification, generate an **App Password**, and use that as
  `MAIL_PASSWORD`. There is no way to make a normal password work.

### When a send fails

Queued, so the request has already returned. The job retries twice (10s, 60s)
and then lands in `failed_jobs`, where Horizon shows it. Nothing is lost and
nobody sees an error page.

Where the queue driver is `sync` — the test suite, and some small
installations — the send is back inside the request, so
`EmailVerificationNotificationController` catches and reports it as a sentence
the user can act on. The reason is logged, never shown: it is a mail-server
setting the person reading it cannot fix, and the exception carries the
mailbox address and sometimes the password.
