# Changelog

All notable changes are recorded here. Format: [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- Kinds of practice (`docs/practice.md`): a required first setup step choosing a
  sector (general hospital, clinic, dental, eye, maternity, children's, laboratory,
  imaging, physiotherapy, mental health, skin, ENT) built from sixteen specialty
  packs, with add-on specialties and module switches. Every suggestion — services
  and prices (converted to the hospital's currency), rooms, wards, lab tests,
  studies, stock, visit reasons, diagnoses, order titles — follows the practice,
  the menu hides modules it does not use, and one click sets the hospital up.
  Settings → Your practice changes it later.
- The books (`docs/finance.md`): a double-entry general ledger with a chart of
  accounts and money books; invoices, payments, voids, card top-ups and charges,
  insurer floats and every stock movement post automatically; money in, money
  out, transfers, journals, opening balances and reversals by hand; income
  statement (with comparisons), balance sheet, trial balance, cash flow and
  account ledgers, as PDF or CSV; finance settings for where each payment lands;
  `finance:backfill` for history. New `finance.record` permission.
- Services carry a specialty and the income account they earn into; card
  top-ups record how they were paid.
- PJAX/SPA foundation: persisted admin shell, Vite asset pipeline, `x-ui` component
  kit, themed AJAX pagination, `WithTable` v2, `CrudModal`, shared FormRequest rules,
  `Shell\NotificationBell`, `Shell\ProfileModal`, `Sequence` document numbering,
  security headers, subscription gate wiring, role-escalation guard, regression guards.
- Documentation: audit & plan, conventions, design system, testing, deployment, this file.

### Changed
- Every in-app link is `wire:navigate`; classic index/create/edit pages replaced by
  Livewire tables and slide-overs; dead assets, layouts, Breeze scaffolding and legacy
  product trees removed.

### Fixed
- Date-bomb appointment tests, Livewire update-route middleware, super-admin
  assignment by tenant admins, Horizon/Telescope exposure, plan-limit keys, invoice
  duplication, soft-delete/unique collisions, device-token hijack, float money paths.

### Security
- See "Fixed"; plus API login throttling, Sanctum token expiry/prefix, no-store on
  authenticated pages, private disk not served, interim root `.htaccess` denies.
