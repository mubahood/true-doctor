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

## Ministry reports (HMIS)

The web's Ministry reports page as data (`Reports → Ministry reports (HMIS)`):
HMIS 105 (outpatients) and 108 (inpatients) for a month, 033b (surveillance)
for a week. Permission `reports.view`; 404 when no hospital is in context (a
super admin over the API), so a form never adds up every hospital. The
figures come from `HmisReports::forForm()`, the call the page, its PDF and its
CSV make, so the four always count the same days. A visit is counted by its
primary coded diagnosis; what cannot be counted is listed as missing data.

### `GET /reports/hmis`

```json
{
  "version": "HMIS 105/108 (2020), 033b (2020)",
  "forms": [
    {"key": "105", "label": "HMIS 105 · outpatients (monthly)", "period": "month"},
    {"key": "108", "label": "HMIS 108 · inpatients (monthly)", "period": "month"},
    {"key": "033b", "label": "HMIS 033b · surveillance (weekly)", "period": "week"}
  ],
  "default_month": "2026-09",
  "default_week": "2026-10-12",
  "notifiable": {"days": 7, "total": 1, "cases": [{"label": "Measles", "count": 1}]}
}
```

`default_month` is the last whole month (the one being reported), `default_week`
last week's Monday, as the page opens. `notifiable` is the dashboard's alert:
notifiable conditions coded in the last seven days, most cases first, to report
to the district surveillance focal person.

### `GET /reports/hmis/{form}?month=YYYY-MM&week=YYYY-MM-DD`

`{form}` is `105`, `108` or `033b` (anything else is a 404). `month` is read by
105 and 108, `week` (any day of the week, Monday to Sunday) by 033b; either
missing is the default above. 422 `validation_failed` (`errors.month`,
`errors.week`) for a month or day that does not read.

```json
{
  "form": "105", "label": "HMIS 105 · outpatients (monthly)", "version": "…",
  "period": {"kind": "month", "month": "2026-09", "week": null, "from": "2026-09-01", "to": "2026-09-30", "label": "September 2026"},
  "bands": [{"key": "0_28d", "label": "0-28 days"}, {"key": "29d_4y", "label": "29 days-4 years"}, …],
  "lines": [
    {"label": "New attendance", "counts": {"0_28d": {"male": 0, "female": 1}, …, "20y": {"male": 7, "female": 5}}, "total": 13},
    {"label": "Re-attendance", "counts": {…}, "total": 0},
    {"label": "Malaria, confirmed", "counts": {…}, "total": 2}
  ],
  "notes": [{"label": "All other diagnoses", "value": 3}, {"label": "Visits this month", "value": 40}],
  "conditions": [],
  "missing": [{"visit_uuid": "…", "visit_no": "V-20260912-004", "patient": "…", "patient_uuid": "…", "why": ["no age", "no coded diagnosis"]}],
  "missing_total": 5,
  "files": {"pdf": ".../api/v1/reports/hmis/105.pdf?month=2026-09", "csv": ".../api/v1/reports/hmis/105.csv?month=2026-09"}
}
```

- `lines` are the form's rows in the form's order, each a grid of age band ×
  sex (`counts` keyed by `bands[].key`) and its `total`: the attendances and
  every diagnosis line for 105; Admissions, Discharges and Deaths for 108; none
  for 033b.
- `notes` are the figures under the grid: all other diagnoses and the visits for
  105; bed days, absconded and each discharge diagnosis (`Discharge diagnosis:
  Measles`) for 108.
- `missing` is the first 100 visits that could not be counted, as the page
  lists them, and `missing_total` how many there are. `why` holds `no age`,
  `no sex` and/or `no coded diagnosis`: the first two are fixed on the patient
  (`patient_uuid`), the last by coding the visit (`visit_uuid`; empty for an
  admission without a visit). 033b has none.
- For 033b, `period` is `{"kind": "week", "week": "2026-10-12", "from": "2026-10-12", "to": "2026-10-18", "number": 42, "year": 2026, "label": "Week 42 of 2026: 2026-10-12 to 2026-10-18"}`
  and `conditions` the notifiable conditions: `[{"key": "measles", "label": "Measles", "cases": 1, "deaths": 1}]`.

### Files

The web's own controller behind the token (`reports.view`, one hospital):

- `GET /reports/hmis/{form}.pdf`: inline `application/pdf`, laid out like the paper form;
- `GET /reports/hmis/{form}.csv`: a download (`text/csv`).

They take the same `month` or `week`.
