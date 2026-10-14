# Kinds of practice

The first setup step asks what kind of practice the hospital is. The answer
decides what every setup page and module page suggests, which parts of the
system its menu shows, and which income accounts its work earns into. It never
removes anything, and it can be changed at any time from Settings → Your
practice.

## Packs, sectors, templates

**A pack** (`App\Support\Practice\Pack`, one class per specialty in
`app/Support/Practice/Packs`) is one specialty's starter data, written as
tables: departments, priced services, lab tests, imaging studies, stock
categories and items with opening counts, rooms, wards with beds and nightly
rates, visit reasons, complaints, diagnoses, doctor's remarks, order titles,
extra chart accounts, the income account its services earn into, and the
modules it cannot work without.

Packs: general & outpatient, emergency, inpatient, maternity & gynaecology,
paediatrics, surgery & theatre, chronic care, laboratory, imaging, pharmacy,
dental, eye care & optical, ENT, physiotherapy, mental health, skin &
aesthetics.

**A sector** (`App\Support\Practice\Sector`) is a kind of practice as a handful
of packs, the packs it most often adds, and its default modules. Sectors:
general hospital, medical clinic / health centre, dental clinic, eye clinic &
optical, maternity & women's health, children's clinic, diagnostic laboratory,
imaging centre, physiotherapy, mental health, skin & aesthetics, ENT clinic.

**A template** (`App\Support\Practice\Template`) is a hospital's packs merged
in order — sector's first, then the ones it added. Merging is by name, first
wins. Phrases put the practice's own first ("Toothache" before "Follow-up
visit"); generic phrases every practice needs are appended. Prices are
indicative UGX converted by `PriceScale` to the hospital's currency at a rough
rate and rounded to two significant figures — a starting point that is edited
before anything is saved, never a conversion applied afterwards.

**`App\Support\Practice\Practice`** reads `settings.practice`
(`sector`, `packs` added, `modules` or null for the defaults, `chosen_at`).
A hospital that has not chosen reads as a general hospital with every module
on — exactly what every hospital was before — and the migration
`existing_hospitals_are_general_hospitals` records that for hospitals that
already existed so none is pulled back into setup.

`App\Support\SampleCatalogue` keeps its old signatures and now answers from the
current hospital's template, so every importer, `VisitPhrases`, and
`OrderDesk` became practice-aware without changing.

## Modules

`inpatient`, `laboratory`, `radiology`, `pharmacy`, `insurance`, `cards`.
Everything else is always on. A module off is left out of the menu
(`Navigation` entries carry `module`), out of setup (no wards step without
inpatients; the catalogues step shows only the halves in use), and out of the
one-click setup. It is a menu preference, not a permission: the pages stay
reachable, and switching it back on brings everything back.

## Setting up from the template

`App\Services\PracticeService`:

- `choose()` records the answer and nothing else.
- `preview()` says, per part, how many rows of the template the hospital does
  not have yet.
- `apply()` writes them: departments (a code already taken is left off),
  services (with their specialty `category` and income account), rooms, wards
  with their beds, lab tests, imaging studies, stock with its opening count
  through `StockService` as **opening stock** (Dr inventory, Cr opening balance
  equity — not a debt to a supplier), and the chart of accounts. Only names the
  hospital has never used are created (deleted rows count, because the unique
  indexes do), so running it twice adds nothing and an edited price stays
  edited.

The wizard step and the settings page are one component,
`App\Livewire\Onboarding\Steps\Practice`.

## Adding a specialty

Write a pack class, add it to `Practice::PACKS`, and to any sector that should
include or suggest it. If its services should earn into their own income
account, return the account from `accounts()` with a `system_key` and the same
key from `incomeKey()`. `PracticeTest::test_every_sector_builds_a_template_with_unique_names`
checks the result.
