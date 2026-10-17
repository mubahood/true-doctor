# One product, two ways to sell: SaaS and one-off

True-Doctor is sold two ways from the same code:

- **SaaS** — hospitals sign up at true-doctor.online and pay a subscription. We host everything.
- **One-off (the "standalone" edition)** — a client buys a copy once, it runs on **their own server**, and it carries **their own brand**. They pay again only if they want updates after the included period.

There is no second product to maintain. This page explains how that works, how we release and sell a copy, and how we delivered the first one (Divine Dental Clinic, October 2026).

The original design, with every rule and its reasoning, is in [`proposals/standalone-edition.md`](proposals/standalone-edition.md). This page describes what was actually built.

---

## 1. The two products side by side

| | SaaS | One-off (standalone) |
|---|---|---|
| Who hosts it | We do, at true-doctor.online | The client, on their own hosting (cPanel shared hosting is enough) |
| How they pay | Monthly or yearly subscription, after a free trial | Once, for a copy plus a period of updates (for example 12 months) |
| How many organisations | Many hospitals, each walled off from the others | Exactly one: the client's |
| Look | True-Doctor branding | The client's logo, colour and tagline, from the sign-in page to every email and PDF |
| Updates | Automatic: we deploy, everyone has it | The client clicks **Back up and update** on their own screen, while their update period lasts |
| Where the data lives | Our server | Their server. We never see it |
| What the client gets after paying | An account | A signed ZIP file and a signed licence file, from their buyer page |
| Limits | Set by the plan | Set by the licence: update period, and optionally a staff limit. Never patients, beds or records |
| Our admin screens | Super admin: hospitals, plans, subscriptions | Super admin on true-doctor.online: releases, products, licences |

---

## 2. How one codebase does both

**One setting decides the edition:** `APP_EDITION=saas` (the default) or `APP_EDITION=standalone`, in `config/edition.php`. There is no branch, fork or copied folder. Every feature and fix is written once and reaches both.

| Piece | What it does | Where |
|---|---|---|
| `Edition` helper | Answers "which edition am I?" (`isSaas()`, `isStandalone()`, `version()`) | `app/Support/Edition.php` |
| Route guard | `edition:saas` / `edition:standalone` route middleware. The wrong edition gets a 404, as if the page did not exist | `app/Http/Middleware/RequireEdition.php` |
| Menu | Each menu entry can name its edition. The SaaS-only "Platform" section is hidden on a client's copy; "Licence & updates" is shown only there | `app/Support/Navigation.php` |
| One organisation | On a client's copy, database triggers make a second hospital impossible and stop the one hospital being deleted. The model refuses as well | `app/Services/Install/SingleEnterpriseGuard.php`, `Hospital::booted()` |
| Installed marker | A client's copy refuses to run if the installed edition and the setting disagree | `app/Http/Middleware/EnsureInstalled.php` |

**Only on SaaS:** the public website, sign-up, free trials, subscriptions and payment for them, the demo login, and the super-admin panel (including the screens that sell and build one-off copies).

**Only on a client's copy:** the web installer, **Settings › Licence & updates** (version, licence, updates, backups), nightly backups, and the client's brand on the sign-in page.

Everything else (patients, visits, billing, the books, stock, offline Field Mode, the mobile API) is the same code in both.

---

## 3. The licence

A licence is a small signed file, `licence.json`, that the client uploads when installing. It holds:

- the licence id and the client's name;
- the web addresses it may run on (for example `app.divinedentalntinda.org`);
- **updates until** a date;
- optional limits (only `max_staff` today).

It is signed on true-doctor.online with an Ed25519 key (libsodium). Every copy carries the public keys it trusts (`RELEASE_KEYS.json`) and checks the signature itself; no phone-home is needed to run.

**What it controls**
- Installing: the address must be listed, and the release must have been built before the update period ends.
- Updating: no updates after the update period ends.
- Staff accounts, if a staff limit is set.

**What it never does:** lock anyone out of patient care or records. A missing, invalid or expired licence shows a warning banner to administrators, and that is all. Revoking a licence on our side stops new downloads; the client's copy keeps working.

Code: `app/Support/Licensing/` (verifying), `app/Services/Licensing/Signer.php` (signing, SaaS only), `app/Services/StandaloneSalesService.php` (issuing).

---

## 4. Releasing a version

Releases are built on true-doctor.online from **what is already running there**, so a client receives exactly the code SaaS customers are using.

**Super › Standalone releases**
1. Deploy to live as usual (`main`).
2. Enter a version number (for example `1.0.7`) and click to stage it.
3. A background job builds the ZIP:
   - `git archive` of `main` (nothing uncommitted can leak);
   - the production `vendor/` and the committed `public/build`;
   - a signed file list, `PACKAGE.json`.
   Secrets, `.env`, tests and docs are excluded by a strict allow-list.
4. The job then **installs the ZIP from nothing on an empty test database**. If that install fails, the release cannot be published.
5. Click **Publish** when it shows *Ready to publish*. Publishing re-checks the file hash and the signature. Clients see the update the next morning, or straight away when they press **Check now**.

A published release can be **withdrawn** (yanked). It then disappears from the buyer page and the update feed.

**Before publishing, check the commit.** `PACKAGE.json` inside the ZIP names the commit it was built from; it must match live `main`. Release 1.0.3 was published from an old staged build and had to be withdrawn.

Builds run on their own queue (`release-builds`), started by the scheduler. Live's cron runs every 19 minutes, so a build can take up to 19 minutes to start.

**The signing key** is `RELEASE_SIGNING_KEY_ID` / `RELEASE_SIGNING_SECRET` in live's `.env`, with an offline copy kept off the server. If both are lost, installed copies can never verify another update.

---

## 5. Selling a copy

Products are set up under **Super › Standalone products**: name, price (UGX), renewal price, months of updates included, optional staff limit, and **On sale**. They are their own table (`standalone_products`), separate from subscription plans.

**Two ways a client buys**
- **Online:** the client chooses a product on true-doctor.online/standalone, enters their name, email and web address, and pays through Pesapal. The licence is created when the payment is confirmed.
- **Offline** (bank transfer, contract, cash): **Super › Standalone licences › Issue**, with the payment reference. This is how Divine Dental's licence was issued.

**How the client gets their files.** An email (**Send link**) opens their buyer page at true-doctor.online/my-licence. The link works for 24 hours. From there they download:
- the release ZIP;
- `licence.json`;
- the install guide.

They can also renew updates and move to another web address (once every 30 days).

From **Super › Standalone licences** we can also renew, change the address, revoke and reinstate.

---

## 6. Delivering a copy to a client

This is how the first client was set up. Plan for about a day, most of it on the client's details.

### 6.1 Check the hosting
- **PHP 8.2 or newer**, with the usual extensions (the installer lists anything missing), and **MySQL or MariaDB with InnoDB**. Some hosts default to MyISAM; True-Doctor now always creates InnoDB tables.
- **HTTPS** on the address. If the host's AutoSSL is off, issue a Let's Encrypt certificate (we used `acme.sh` with its cPanel deploy hook; it renews itself).
- **A cron job every minute:** `cd ~/true_doctor_app && php artisan schedule:run`. Updates, backups and email all depend on it.
- **An outgoing mailbox** for the system, such as info@ on their domain. Use the server's own hostname on port 465 (SSL), because the `mail.` name often carries the wrong certificate.
- **Don't touch their website.** The clinic's WordPress site and its database were left alone; the system went on a subdomain.

### 6.2 Install
1. Issue the licence for their exact address.
2. Unzip the release in the hosting home folder:
   - `true_doctor_app/` stays there, never public;
   - the contents of `web_root_files/` go into the address's web folder.
3. Open the address. Until it is installed, it goes straight to `/install.php`.
4. The installer asks, in order:
   - the licence file;
   - an empty database;
   - the organisation, its currency and time zone, and the owner's login;
   - email settings.
   It checks every file against the signed list, refuses a wrong address or an expired update period, and sends a test email at the end.

### 6.3 Make it theirs
Use the client's own documents, not their website; their price list was more current than the site.
- **Brand:** square and wide logo, brand colour (it must be dark enough for white text), tagline. Set under **Settings › Hospital letterhead**.
- **Kind of practice:** for example a dental clinic with the drugs module. The menu then speaks their language ("Drugs inventory" instead of "Pharmacy").
- **Price list:** codes and groups; price ranges where they quote a range; tax exemption; each service mapped to an income account.
- **Staff:** departments, user accounts and roles. "Also sees patients" lets an administrator be booked like a doctor. Add the dentists' working hours.
- **The books:** income accounts per treatment line, the clinic's usual expenses, money books (cash, bank, mobile money), and the financial year.
- **Stock categories:** set them up for the drugs inventory.

### 6.4 Hand over
- **A short email** with the address, logins, what is set up, what is still needed, and the first steps. It is the only place passwords appear. Divine Dental's is in the git-ignored `divine-dental-info/handover-email.md`.
- **A plain PDF staff guide** with screenshots of their own system, written by role (front desk, dentists, finance, administrators). It contains no passwords.
- **Before handing over:**
  - check the installer page answers 404;
  - check the scheduler heartbeat on Licence & updates;
  - take the first backup by hand;
  - remove any temporary access you used.

When the host disables shell access, as Namecheap had, everything can still be done over SFTP. Upload a short PHP script protected by a random token, run it once in the browser, and delete it straight away.

---

## 7. Looking after a client's copy

| Task | How |
|---|---|
| Updates | **Settings › Licence & updates › Check now**, then **Back up and update**. The scheduler runs it outside the web request: back up, swap the signed files, migrate, check health. If anything fails, it restores the code and database by itself. The site shows a maintenance page for a few minutes. Without internet: **Upload and update** with the ZIP from the buyer page |
| Backups | Every night at 02:15; the last seven are kept. Each zip holds the database (with the single-organisation triggers), `.env`, the licence and all uploaded files, plus restore instructions. Clients should download one weekly and keep it off the server |
| Warnings | Administrators see a banner when the scheduler stops, the licence is missing or invalid, the address does not match, or the update period has ended. Patient care carries on regardless |
| Renewals | The client renews on their buyer page, or we use **Renew** under Super › Standalone licences. The update period extends by the product's months |

---

## 8. What the first client taught us

Divine Dental's install (1.0.4 onwards) found problems that tests had not. Each fix shipped to both editions.

| Release | Fix |
|---|---|
| 1.0.2 | (Before the first client.) The installer works before Laravel can boot, and finds the app from a nested web folder |
| 1.0.4 | InnoDB everywhere: the host defaulted to MyISAM and the 1.0.2 install stopped at migration 16. Also: the installer sets the clinic's own time zone and email sender name. A copy with no `.env` now opens the installer instead of an error page. The owner's email is marked verified. ZIP file permissions are set. Branding was added: logo, colour and tagline from the sign-in page to every email and PDF |
| 1.0.5 | The picture check on the forgot-password page loads on a client's copy |
| 1.0.6 | What a dental practice needed: price ranges, an administrator who also sees patients, and "Drugs inventory" wording |

**Lessons**
- **Test a release by installing the ZIP for real** on an empty database before trusting it. The automatic proof install now does this for every release.
- **Check the commit inside the ZIP before publishing** (see section 4).
- **Shared hosting differs in small ways:** default database engine, SSL, mail certificates, folder layout. The installer should check each of these, not assume them.
- **Write the client's guide from their own screens.** Check every instruction against the code: the first draft would have overcharged scaling (quantity per tooth) and booked existing stock as supplier debt.

---

## 9. Where to look

| Topic | Read |
|---|---|
| The full design and its rules | [`proposals/standalone-edition.md`](proposals/standalone-edition.md) (§7.1 is superseded: products are their own table) |
| Build progress and history | [`proposals/standalone-execution-plan.md`](proposals/standalone-execution-plan.md) |
| Decisions with reasons | [`decisions.md`](decisions.md), the entries dated 2026-10-15 |
| The buyer's install guide | `resources/standalone/INSTALL.md` (ships inside every ZIP) |
| SaaS subscriptions and plans | [`subscriptions.md`](subscriptions.md), [`plan-limits.md`](plan-limits.md), [`super-admin-panel.md`](super-admin-panel.md) |
| Tests | `tests/Feature/Standalone*Test.php`, `HospitalBrandingTest.php`, `ClinicPracticeFitTest.php` |
