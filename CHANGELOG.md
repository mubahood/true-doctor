# Changelog

All notable changes are recorded here. Format: [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
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
