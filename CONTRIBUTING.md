# Contributing

1. Branch from `main`; one feature/fix per pull request.
2. Follow [`docs/livewire-conventions.md`](docs/livewire-conventions.md) and
   [`docs/design-system.md`](docs/design-system.md). Business rules live in Services,
   validation in FormRequests (`rulesFor()`), authorization in Policies.
3. Every change ships with tests (see [`docs/testing.md`](docs/testing.md)); never delete
   or weaken existing coverage.
4. `composer ci` must be green: Pint, PHPStan, empty-file and secrets scans, the suite.
   Assets must build (`npm run build`).
5. Never commit `.env*` (other than `.env.example`), dumps, backups or credentials.
6. Record non-obvious decisions in `docs/decisions.md` (date, decision, reason).
7. Commit messages: imperative summary line, body explaining *why*.
