# Support contact in the API

Who to ask for help, and the prefilled messages, so the app never holds the number.

## Where it appears

| Endpoint | Key | Whose contact |
|---|---|---|
| `GET /api/v1/meta` (and `meta` of `GET /bootstrap`) | `data.support` | `SupportContact::forHospital`: the licence's partner, else the hospital's own IT, else the platform |
| `GET /api/v1/public/plans` | `data.support` | the platform |
| `GET /api/v1/public/demo` | `data.support` | the platform (known before anyone signs in) |

## Shape

```json
{
  "whatsapp": "256700111222",
  "whatsapp_display": "+256 700 111 222",
  "phone": "+256 700 111 222",
  "email": "help@example.com",
  "hours": "Monday to Saturday, 8:00 to 20:00",
  "who": "platform",
  "templates": { "admin.help": "Hello True-Doctor, I am :role at :hospital and I need help with :screen." }
}
```

- `who` is `platform`, `partner` or `own`: who answers.
- Any of `whatsapp`, `phone`, `email`, `hours` may be `null`; the app shows only what it has.
- `templates` are keyed by context (`site.*`, `auth.signin`, `trial.welcome`, `admin.*`, `app.*`, `standalone.*`). The app fills `:placeholders` itself with a page or screen name, the hospital's name, the person's role, an error reference, a version or a diagnostics report. **Never a patient or a record.**
- An older server has no `support` key: the app shows no support line until a server says so.

## Registration

`POST /api/v1/public/register` accepts `whatsapp` (the owner's number with the country code). It is optional over the API, required on the web form.
