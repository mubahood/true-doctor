# 08. The mobile app (`true-doctor-mobo`)

**Goal:** the app is released, findable on Google Play, reachable for help in one tap, and safe on a lost phone. It wears the hospital's brand, connects to a client's own server by scanning a code, opens the exact record from a notification, and speaks Luganda and Swahili.

**How this file relates to the app's own plan:** it adds to `mobo/docs/SUPER_APP_PLAN.md` (Waves 1 to 4) and `mobo/PLAN.md`. Where they overlap, those files win on detail and this file sets the priority. The app's rules in `mobo/AGENTS.md` and `mobo/docs/AGENT_GUIDE.md` still hold:

- the server decides and the app shows;
- three tiers of offline;
- `Idempotency-Key` on every write;
- the web's design tokens;
- every web menu item has a native screen.

**What exists and stays:**

- 47 native routes and the web-only fallback;
- the sync engine with outbox and conflicts;
- read-through copies and "Make ready for offline";
- the PIN lock, idle sign-out, privacy cover and clock check;
- the demo lobby with roles and missions;
- local reminders;
- about 700 tests and the screenshot harness (`TD_SHOTS=<dir> flutter test test/screenshots/...`).

---

## 1. Release it (P0)

- **One app ID before the first store upload,** because it can never change afterwards.
  - Today Android is `online.truedoctor.true_doctor`, iOS is `online.truedoctor.trueDoctor`, and `PLAN.md` says `online.truedoctor.app`.
  - Choose one, record it in `docs/decisions.md` on both repos, and set both platforms.
- **Version from one place:** read it with `package_info_plus` and remove the hand-written `appVersion` in `lib/main.dart:31`.
- **Push the 32 local commits.**
- **CI:** the self-hosted runner (07 §1) runs analyze, test and the Android build.
- **`CHANGELOG.md` and git tags** per release.
- **Google Play:**
  - internal testing, then a closed test with Divine Dental staff and the demo, then production;
  - the Data safety form filled from the truth (what is stored on the phone, encrypted or not, what is sent);
  - the privacy policy URL (`/privacy`);
  - the support email and phone (03);
  - screenshots from the harness.
- **Store listing that ranks (ASO):**
  - **Title:** "True-Doctor: Clinic & Hospital".
  - **Short description:** "Patients, visits, lab, pharmacy, billing. Works offline."
  - **Full description:** the words clinic owners search for (clinic management, hospital system, patient records, dental clinic, pharmacy, Uganda, offline), plus the WhatsApp number.
  - A Swahili listing (P1).
  - **Ask for a review** after a success moment (the day's cash-up balanced, the 50th patient), never during work.
- **Real-device checks** before production: iOS backup exclusion, the QR scanner, notifications, a phone with 2 GB RAM, Android 8.

## 2. Help in one tap (P0)

- A "Get help" card on the account page with WhatsApp, call and email, from `meta.support` (03).
- On the signed-out landing and the sign-in screen: "Talk to us on WhatsApp", from `public/plans`.
- Diagnostics gains "Send to support on WhatsApp".
- The update, subscription-ended and web-only screens carry the support line.

## 3. Push and deep links (P1)

- **Push:**
  - create the Firebase project and switch the server's push driver from `null` to FCM (`app/Services/Push/*`, `config/push.php`);
  - the app registers its token (`devices/{id}/push-token` exists).
  - Notifications keep the standard payload with a lock-screen-safe public message (`AppNotification`).
- **Deep links:**
  - Android App Links and iOS Universal Links for `https://true-doctor.online/a/...`;
  - for a client's own server, a custom scheme plus the stored server.
  - A notification or link opens the **exact record**, not a list.
  - The server's route names map to app routes (the `route` field in the payload exists).
- **Share links** (05 §4) open in the app when it is installed and signed in to that hospital, and in the browser otherwise.

## 4. Own servers made easy (P1)

- **Connect a phone by QR:**
  - on the web, Settings → Devices → "Connect a phone" shows a QR holding the server address and a one-time enrolment token that lasts 10 minutes;
  - the app scans it (`mobile_scanner` exists), checks the server (`openapi.json` probe exists), and opens sign-in for that server.
- **A saved server list:** "True-Doctor cloud" plus any own servers. Switching keeps each server's own database (already one file per server).
- **The licence's support contact** shows for own servers (03 §1).

## 5. The hospital's brand (P1)

- Apply `meta.brand.colour` at runtime when it passes the contrast test; otherwise keep the default blue. It is parsed today and unused.
- After the first connection, the hospital's logo and name show on the sign-in and lock screens.
- **Per-client app builds (P2):** a flavor with the client's name and icon, for a standalone client who wants "their" app in the store. Same code, one config file per client, built by CI.

## 6. Safe on a lost phone (P1)

- **Encrypt the local database** with SQLCipher. The key lives in secure storage and is wiped on sign-out.
- **Move form drafts** from shared preferences into the encrypted database. Drafts can hold clinical text, and today they are plain.
- **Biometric unlock** as an alternative to the PIN (`local_auth`).
- **Remote sign-out:** the owner removes a device on the web, and the app wipes its data at the next contact while keeping unsent work exportable for the owner.
- **Crash reports** to Sentry or GlitchTip, with names, phones and record text scrubbed (07 §1).

## 7. Speak the user's language (P1)

- `flutter_localizations` with English, Luganda and Swahili, following the server's list in `capabilities`.
- Send `Accept-Language` on every request, so the server answers in the same language.
- Reception and patient-facing screens first, as on the web (06 §5).

## 8. More done offline (P1, Wave 3 of the super-app plan)

- Queue the next clinical actions offline: check-in, open a visit, vitals (exists), orders and prescriptions as drafts that send when online, and the dental chart (04 §3).
- Each action stays honest about "waiting to send". Money and dispensing stay online-only unless the server can guarantee no double stock movement.
- **Background sync** with WorkManager, within battery rules.

## 9. Fixes found in the audit (P0 to P1)

- **Nurse shortcuts:** the quick actions `round-mode` and `handover` are listed (`dashboard_page.dart:246`) but have no buttons, and `extraQuickActions` is never filled. Wire them.
- **Ward screens:** `wardScreens` is not merged into `destinations.dart`. Add round and handover as destinations.
- **Phone numbers:** stop guessing the dial code from the currency (`widgets/td_scope.dart:24`) and use the server's normalised number (06 §3).
- **Patient messages:** once 05 is live, send through the server's channels. Keep the staff phone's WhatsApp hand-off as the fallback.
- **Radiology:** lift the 50-row cap with paging.
- **Docs:** update the test count in `AGENTS.md` and tick `SUPER_APP_PLAN.md` for the waves already committed.

## 10. Tablet and desk (P2)

- Two-pane layouts on tablets (list and record side by side) for reception, lab and the dental chair.
- The Windows build (it exists) as a reception desk app with the thermal printer (06 §6).
- Dark mode, for night shifts in the ward.

## 11. Definition of done for this file

- The app is in production on Google Play with one app ID, a true Data safety form, the support contact and real screenshots.
- Push opens exact records.
- An own server connects by QR.
- The local data and drafts are encrypted.
- Crash reports arrive.
- Luganda and Swahili work for the reception screens.
- CI is green on every commit.
