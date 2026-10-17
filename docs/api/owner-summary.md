# The owner's daily summary (roadmap 09 §2)

A short message each morning to a hospital's owner (its administrator) with the day before in figures: patients seen, money in by method (and how much of it paid older bills), what was left unpaid that day and what is owed in total, the stock alerts, the top three "worth a look" items, and today's appointments. Off until the owner turns it on.

## How it works

- **Service:** `App\Services\OwnerSummaryService` (preferences, compose, send). Screens and the API only call it.
- **Channels:** `App\Services\Messaging\Owner\OwnerChannel`, with `EmailOwnerChannel` (plain-text email to the sign-in address) and `SmsOwnerChannel` (one text through the patients' `SmsTransport`, the log driver until a gateway is configured). On the online service a text takes messages from the hospital's balance like any other and gives them back if it fails; with no messages left it is not sent and the row says so. WhatsApp (Phase 3) is one more class in `OwnerSummaryService::CHANNELS`.
- **When:** `owners:daily-summary` runs every 30 minutes (`ScheduledRun::every`). For each owner who opted in, it sends once their local time (the hospital's time zone) has passed their chosen time (default 07:30), and never inside the hospital's quiet hours (Settings, Messages; 21:00 to 07:00 by default). It waits until they end: a time chosen inside them (22:30 with quiet from 21:00) means "as soon as they end". Suspended hospitals get nothing.
- **Once a day:** `owner_summaries` has one row per owner per day (`unique(user_id, summary_date)`); the row is claimed before sending, so two runs at once still send one summary. A failed send is retried on the next runs, three attempts in all. A send left "sending" for 30 minutes is marked failed and retried, so delivery is at least once: a process that dies after the message left but before its row was updated sends it again.
- **Where the choice is kept:** `hospitals.settings['owner_summary'][user id] = {enabled, channel, at}`, written under a row lock.
- **Web:** Settings, Messages shows a "My daily summary" card to the hospital administrator only (`App\Livewire\Settings\OwnerSummary`), with a preview of what it would say today and the last seven days.

## Endpoints

Both need a Sanctum token for a hospital administrator; anyone else gets `403 forbidden`.

### `GET /api/v1/owner-summary`

```json
{
  "success": true,
  "data": {
    "enabled": true,
    "channel": "email",
    "at": "07:30",
    "channels": [{"value": "email", "label": "Email"}, {"value": "sms", "label": "Text message"}],
    "preview": "Kato Medical Centre, Fri 16 Oct 2026\nPatients seen: 23.\nMoney in: USh 1,250,000 (Cash USh 800,000, Mobile money USh 450,000).\n...",
    "recent": [{"date": "2026-10-16", "channel": "email", "status": "sent", "error": null, "sent_at": "2026-10-17T04:30:12+00:00"}]
  }
}
```

`recent` is this owner's last seven days, newest first. `status` is `sending`, `sent` or `failed`; `error` says why in words.

### `PUT /api/v1/owner-summary`

Body: `{ "enabled": true, "channel": "email" | "sms", "at": "HH:MM" }`. Answers like `GET` without `preview` (it runs every figure of the summary, so only `GET` works it out).

- `422 validation_failed` for an unknown channel or a bad time, and when the summary is turned on by text with no mobile number on the owner's profile (or by email with no address).
- Safe to retry with an `Idempotency-Key` (the API group's middleware).
