# Security policy

True-Doctor processes protected health information. Please report vulnerabilities
privately to the maintainers (see `composer.json` / repository owners) rather than
opening a public issue; include reproduction steps and affected versions. We aim to
acknowledge within 3 working days.

Controls in place: per-hospital tenancy enforced by a global scope and middleware
ordering; policy-based authorization on every action (web, Livewire and API);
password policy and forced first-login change; per-email login throttling; Sanctum
tokens with expiry; no-store on authenticated pages; security headers; private disk
for documents/photos streamed only through policy-gated controllers; secrets scan
and dump-file guard in CI. Deploy with `public/` as the document root
(`docs/deployment.md`).
