# 05 · Editions, releases and client servers

Full story: [`../editions.md`](../editions.md) (read it for licences, selling, delivering a copy). This chapter is the operating rules for an agent.

## The model

| | SaaS | Standalone (one-off) |
|---|---|---|
| Host | true-doctor.online (us) | Client's own cPanel hosting |
| Orgs | many | exactly one |
| Updates | we deploy | client clicks *Back up and update* while their update period lasts |
| Brand | True-Doctor | client's logo/colour/tagline everywhere, including PDFs and emails |
| Licence | – | signed Ed25519 `licence.json` (addresses, updates-until, optional staff limit). Never locks patient care |

`Edition::isSaas()/isStandalone()`, `edition:saas|standalone` route middleware (wrong edition → 404), menu entries carry an edition.

## When you write a feature, ask

- Does it assume many hospitals? (Plans, sign-up, super-admin → SaaS only.)
- Does it need something shared hosting lacks (shell, `exec`, long-running workers, instant cron)?
- Does it show a brand? Use `HospitalBrand` / `DocumentBrand`; never a hard-coded logo.
- Does it add a class file or route that a hotfix might need? See below.

## Releasing a standalone version (SaaS server only, Super › Standalone releases)

1. Deploy `main` to live as usual (only when the owner asks).
2. Enter the version and stage it. A background job (`release-builds` queue, started by the scheduler; live cron is every 19 min so allow time) does `git archive` of `main`, adds production `vendor/` and committed `public/build`, writes the signed file list `PACKAGE.json`, then **installs the ZIP from nothing on an empty database** as proof.
3. **Before publishing, check `PACKAGE.json`'s commit equals live `main`.** (1.0.3 was published from an old staged build and had to be withdrawn.)
4. Publish. Clients see it next morning or on *Check now*. A bad release can be withdrawn.

Because releases are built from `main`, **uncommitted work never ships, and unpushed commits don't either**. The next release (1.0.7) will carry the PDF fix, the API work, the patient record and the compact UI — which is why nothing is released until the owner decides.

Signing key: `RELEASE_SIGNING_KEY_ID` / `RELEASE_SIGNING_SECRET` in live `.env`, with an offline copy. Lose both and installed copies can never verify another update.

## Hotfixing a client's live server (emergencies only)

Only when the owner says to ("fix it asap"). Precedent: Divine Dental, invoice PDF 500, October 2026.

1. **Reproduce locally** under the same conditions (split layout, classmap autoload). A server-side 500 you can't reproduce is a guess.
2. **Smallest possible change, in files that already exist.** Standalone uses `classmap-authoritative`: a *new* class file will not be found and takes the whole site down. (We did exactly that: ~1 minute outage; restored from the saved original at once.)
3. Hosts without shell: SFTP only (port 21098 for Namecheap). Credentials are in the git-ignored `divine-dental-info/`; never paste them anywhere.
4. **Back up each file you replace**, upload, then verify with real requests (every printable returned a real PDF, not a 500). Have the rollback command ready *before* you upload.
5. Put the same fix in the repo (commit), so the next release includes it and doesn't conflict. Say plainly that the live copy was patched by hand and which files.
6. If you used a temporary access script, delete it immediately.

## Delivering a client's copy

Follow `../editions.md` §6. Client-facing material: **short email** (address, logins, what's set up, first steps — the only place passwords appear) and a **plain PDF guide** by role with screenshots of *their own* system, no passwords, no colourful design. Verify every instruction against the code before writing it. Never publish a client's data or credentials anywhere.

## Local environment

Local app: `http://localhost:8888/true-doctor` (MAMP). Demo accounts: `docs/demo-accounts.md` (password `password1`). Run `php artisan migrate` after pulling: a missing local migration once caused a bogus 500 (`schedules`).
