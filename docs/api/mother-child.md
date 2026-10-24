# Maternity and child health API

The pregnancy record, the immunisation register and the growth chart: the same services and rules as the web's tabs (`docs/mother-child.md`). All under `/api/v1`, authenticated. Reading needs `visits.view`; writing needs `visits.vitals` or `visits.diagnose`. Every write takes an optional client `uuid` (a retry with the same uuid records once) and, like every write, an `Idempotency-Key`.

## Pregnancy

| Method | Path | Body | Answer |
|---|---|---|---|
| GET | `patients/{uuid}/pregnancy` | | `current` (a pregnancy with `schedule`, or null), `past` (newest first), `risk_factors` and `checks` (catalogues: `value`, `label`) |
| POST | `patients/{uuid}/pregnancies` | `lmp?`, `edd?` (one of the two), `gravida`, `para?`, `risk_factors[]?`, `risk_notes?`, `birth_place?`, `birth_companion?`, `transport?`, `blood_donor?`, `birth_plan_notes?`, `uuid?` | 201: the pregnancy with `schedule` |
| PATCH | `pregnancies/{uuid}` | any of the above; a key not sent keeps its value | the pregnancy with `schedule` |
| POST | `pregnancies/{uuid}/contacts` | `seen_on?` (today), `blood_pressure?` ("120/80"), `weight?`, `fundal_height?`, `fetal_heart_rate?`, `checks[]?`, `notes?`, `visit_uuid?`, `uuid?` | 201: `contact`, `pregnancy` |
| POST | `pregnancies/{uuid}/close` | `status` (`delivered`, `ended`), `ended_on?`, `notes?` | the pregnancy |

A pregnancy: `uuid`, `status`, `status_label`, `lmp`, `edd`, `edd_from_lmp`, `gravida`, `para`, `risk_factors`, `risk_notes`, `birth_plan` {`place`, `companion`, `transport`, `blood_donor`, `notes`}, `ended_on`, `outcome_notes`, `contacts[]`.

A contact: `uuid`, `seen_on`, `contact_no` (the scheduled contact it counts against), `gestation_days`, `gestation_label`, `blood_pressure`, `weight`, `fundal_height`, `fetal_heart_rate`, `checks`, `notes`, `recorded_by`.

`schedule`: `gestation_days`, `gestation_label` ("24 w 3 d"), `trimester`, `contact_now`, `contacts[]` (`no`, `week`, `due_on`, `status`: `done`/`missed`/`due`/`upcoming`, `seen_on`), `next`, `due_checks[]` (`key`, `label`), `missed_checks[]` (`key`, `label`, `contact`), `missed_contacts`, `flags[]` (`key`, `label`, `tone`: the recorded risks plus her age, five or more births, a raised BP at the last contact, past 41 weeks).

## Immunisation

| Method | Path | Body | Answer |
|---|---|---|---|
| GET | `patients/{uuid}/immunisations` | | `dob`, `card[]`, `counts` {`given`, `due`, `overdue`, `next`}, `source` |
| POST | `patients/{uuid}/immunisations` | `doses[]`: `dose_key`, `given_on?` (today), `given_elsewhere?`, `batch_no?`, `notes?`, `uuid?`; `visit_uuid?` | 201: `recorded` (uuids), `card`, `counts` |
| POST | `immunisations/{uuid}/void` | `reason` | `uuid`, `voided_at` |
| GET | `immunisations/due` | `?on=YYYY-MM-DD&status=due\|overdue&q=&page=&per_page=` | paged list: `patient_uuid`, `patient_no`, `name`, `age_label`, `phone`, `overdue`, `doses[]` (`key`, `vaccine`, `dose`, `due_on`, `status`, `tone`); `meta.on`, `meta.overdue_total` (overdue doses across the whole list) |

A card row: `key`, `vaccine`, `dose`, `age_label`, `due_on`, `status` (`given`, `due`, `overdue`, `upcoming`, `missed`), `tone`, `given_on`, `given_elsewhere`, `batch_no`, `uuid` (of the dose given), `recorded_by`.

## Growth

| Method | Path | Body | Answer |
|---|---|---|---|
| GET | `patients/{uuid}/growth` | | `assessment`, `points[]`, `reference`, `source` |
| POST | `patients/{uuid}/growth` | `measured_on?` (today), `weight?` (kg), `height?` (cm, lying under two), `muac?` (cm), `oedema?`, `notes?`, `visit_uuid?`, `uuid?` (at least one measure) | 201: `uuid`, `assessment` |

A point (oldest first; the clinic's measurements and the visits' vitals, one per day): `date`, `weight`, `height`, `muac`, `oedema`, `source` (`clinic`, `visit`), `uuid`, `age_days`, `age_label`, `weight_z`, `height_z`, `weight_band`, `height_band`, `muac_band` (each band `key`, `label`, `tone`, or null).

`assessment`: `points`, `weight` / `height` {`value`, `date`, `z`, `band`}, `muac` {`value`, `date`, `band`}, `oedema`, `faltering` {`flag`, `reason`}.

`reference`: what the weight-for-age chart is drawn on, the same answer the web's chart reads
(`GrowthService::weightReference`): `months` (the x axis, from birth to a little past the latest weighing: a
year at least, five years at most), `kg_max` (the top of the kg axis: the +3 line and every weighing fit under
it) and `lines[]`, one per WHO line (`z`: -3, -2, 0, 2), each `points[]` of `[month, kg]` a month apart. Null
without a date of birth and a sex. Plot a point at `age_days / 30.4375` months.

## Errors

422 with the field: `lmp`, `edd`, `gravida`, `para`, `risk_factors`, `patient` (not female, or a pregnancy already open), `pregnancy` (closed), `seen_on`, `blood_pressure`, `checks`, `doses.0.dose_key` (not on this child's schedule, or already given), `doses.0.given_on` (future, before birth, too young, too soon after the previous dose), `measured_on`, `weight`. 403 when the role cannot; 404 for another hospital's record.

## The patient summary

`GET patients/{uuid}/summary` adds `pregnancy`, `immunisation` and `growth` to `sections`, by the same rules as the web's tabs, with one block each:

- `pregnancy`: the current pregnancy in brief (`uuid`, `edd`, `gestation_label`, `gravida`, `para`, `next_contact`, `missed_contacts`, `flags` as labels), or null when none is open;
- `immunisation`: the `counts`;
- `growth`: the `assessment`.

The tabs show: Pregnancy for a woman of 12 to 55 in a practice with the maternity pack, Immunisation for a child under 15 and Growth for a child under five in a practice with the paediatrics or maternity pack, and each of them for anyone who already has such a record.
