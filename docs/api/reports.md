# Reports for the app

All under `/api/v1`, Sanctum token, permission `reports.view` (403 `forbidden` otherwise).

## Reports page

`GET /reports` and `GET /reports/pdf`: see `ReportController` (the web's Reports page as data).

## Monthly reports

`GET /reports/monthly`

| Query | Meaning |
|---|---|
| `type` | `income` (default), `expenses` (the Expenditure report), `receivables`, `patients` |
| `view` | `summary` (default) or `detail` |
| `month` | `YYYY-MM`; this month when absent |
| `from`, `to` | `YYYY-MM-DD`, both or neither; a range overrides `month` (swapped if back to front, at most 366 days) |
| `page`, `per_page` | detail only; `per_page` 1 to 200, default 50 |

422 `validation_failed` for a bad month, date or type. 404 when no hospital is in context.

For a reader without `visits.clinical`, the patient records' `complaint` and `diagnosis` are empty strings and the summary has no `diagnoses` table. Counts, totals and summaries may be up to 60 seconds old.

Every answer carries `type`, `view`, `title`, `period {from, to, month, label}`,
`clinician_label` ("Dentist" for a dental clinic, else "Doctor"), `types` and
`views` (`[{key, label}]`) and `files {pdf, xlsx, csv}` (the URLs below, for
the same choice).

`view=summary` adds:

- `figures`: `[{label, value, type, note}]`, the headline figures;
- `tables`: `[{key, title, columns:[{key,label,type}], rows:[{...}], total?, note?}]`.

`view=detail` adds `columns`, `rows` (one page), `totals` (`[{label, value, type}]`,
over every row, not the page), and the envelope's `meta {current_page, per_page, total, last_page}`.

A column `type` is `text`, `date` (`YYYY-MM-DD`), `money` (a decimal string,
`"25000.00"`), `int` or `percent` (a decimal string to one place, or null when
there was nothing in the period before). Money is never a float.

Files, the web's own controller behind the token:

- `GET /reports/monthly/{type}/{view}.pdf`: inline `application/pdf`;
- `GET /reports/monthly/{type}/{view}.xlsx` and `.csv`: downloads.

They take the same `month` or `from`/`to`.
