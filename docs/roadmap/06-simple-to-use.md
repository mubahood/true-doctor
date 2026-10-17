# 06. Simple to use

**Goal:** a receptionist trained for 20 minutes can register, check in and book. A doctor sees the next patient in one tap. A cashier closes the day without help. The power stays behind the screen (the AGENTS rule "powerful behind, simple in front").

**What exists and stays:**

- role dashboards (`app/Support/Dashboard/*`, 11 variants);
- `NextActionsService` and `AgendaService`;
- writing aids (`VisitPhrases`);
- the design system (`docs/design-system.md`, compact, flat, square, Inter);
- slide-over editors and Livewire tables;
- in the app: the command palette, the first-day card per role, and form drafts.

---

## 1. Measure the ten daily journeys first (P0)

Before changing screens, time them. Count clicks, fields and seconds on the web and in the app for:

1. Register a new patient and check them in.
2. Book an appointment.
3. Consult: vitals, complaint, diagnosis, order a test, prescribe.
4. Enter a lab result and release it.
5. Dispense a prescription.
6. Take a payment (cash, mobile money, insurance part).
7. Admit, do a ward round, discharge.
8. Receive stock.
9. Close the cashier's day.
10. Find a patient from a name, a phone or a number.

Record each in `docs/roadmap/journeys.md` with a target (for example "register and check in: under 45 seconds, at most 6 required fields"). Re-measure after each change. Screenshots from the existing harness show before and after.

## 2. The next obvious action, everywhere (P1)

- **Each role's home leads with one big action:**
  - reception: Register, Check in, Book;
  - doctor: Next patient;
  - nurse: Vitals due, Doses due;
  - lab: Samples waiting;
  - pharmacy: To dispense;
  - cashier: Waiting to pay;
  - owner: Today's money.

  Below it go the next actions from `NextActionsService`. Nothing else competes.
- **No dead ends.** Every finished task offers the next step: after check-in → "Send to vitals"; after consultation → "Send to cashier"; after payment → "Send receipt on WhatsApp".
- **The same command palette on the web and in the app** (Ctrl+K on the web), with the same actions from one server list: find a patient, new visit, take payment, contact support (03).

## 3. Fewer fields, better defaults (P1)

- **Required fields only at the desk.** For example, name, sex, age or date of birth, and phone. Everything else can be completed later and shows as "complete the record" in the next actions.
- **Defaults remembered per person:** the last doctor, department and payment method. Defaults per practice come from the pack.
- **Age accepted as years or months** when the date of birth is unknown, stored with an "estimated" flag.
- **Phone numbers:**
  - typed in any local form (0772…, 772…, +256772…);
  - normalised once on the server to E.164 using the hospital's country;
  - this replaces the app's guess from the currency (`mobo/lib/widgets/td_scope.dart`).
- **Specialty templates:** a visit opened in a dental clinic shows the dental chart; in an eye clinic, the eye exam (04 §5). There is no menu digging.

## 4. Help where the question is (P0 to P1)

- **A "?" on every screen** that opens the user guide page for that screen and offers WhatsApp support with the screen named (03) (P0 for the link, P1 for the guides).
- **A user guide written for staff, not developers:**
  - `/help` on the public site, one page per screen and per role;
  - real screenshots, short steps, local examples;
  - versioned with the release.
- **First-day tours per role** (the app already has a first-day card) and one short video per role on YouTube (02 §7).
- **Human error messages:** what went wrong, what to do, and a reference for support. No stack traces, no codes alone.

## 5. Languages (P1)

- **Phase 1:**
  - patient-facing words in English, Luganda and Swahili: messages (05), receipts, results covers, booking pages and the verification page;
  - the reception screens.
  - Clinical screens stay in English, with clinical terms in English.
- **Phase 2:** the whole back office through Laravel's `__()`, with `lang/{en,lg,sw}`. The app follows (08).
- **Clean up `lang/*/verification.php`,** which holds copy left over from another product.

## 6. Printing the way clinics print (P1)

- **Thermal receipts:** 58 mm and 80 mm layouts for receipts and queue tickets, beside the A4 PDFs.
- **Bluetooth printing from the app:** reuse Budget Pro's printer code (`Desktop/github/budget-pro-mobo/lib/pos/receipt_printer.dart`) as the pattern.
- **A4 documents** keep the hospital brand and gain the verification QR (05 §4).

## 7. Setup in 15 minutes (P1)

- **Import from Excel** with downloadable templates, a preview and an error report per row: patients, the price list, stock with batches and expiry, and staff.
- **"Start with sample data" or "start empty",** with a one-click removal of sample data.
- **The setup checklist** stays on the dashboard until the first patient is registered and the first visit is paid (02 §6). The time taken is recorded.

## 8. Works on what clinics have (P1)

- **Back-office page budgets:** each Livewire screen held to a query count (as in `PerformanceBudgetTest` in Budget Pro) and an asset weight.
- **A low-bandwidth mode:** no images in lists, compressed attachments, the app's offline Field Mode recommended when the network is poor.
- **Big touch targets** (44 px) on tablets used at the desk and in the ward.

## 9. Accessibility (P1)

- Keep the WCAG AA contrast tests (web and app).
- Full keyboard use of the core journeys.
- Labels on every input.
- Focus that is always visible.
- Screen-reader names on icon buttons.

## 10. Measures

- Time to first patient after sign-up.
- Time per journey (§1).
- Support conversations per hospital per month (from 03 §4).
- The after-visit score (05).
- An owner score asked once a quarter in the back office ("How likely are you to recommend True-Doctor?").
