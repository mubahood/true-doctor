# Documents

Everything this system prints. Eight documents leave the building — an invoice a
patient keeps, a receipt they argue with, a lab result another clinic reads, a
usage statement an insurer files, a full visit report they carry to another
hospital — and for most people who receive one, it is the only part of the
software they will ever see.

Two rules cover all of them.

## 1. A document opens; it does not download

A document is read before it is kept. Sending somebody's browser straight to
their downloads folder for something they only wanted to look at is how a
folder fills with `receipt (3).pdf`.

So both halves are held to it:

* the **controller** streams the PDF — `->stream($name)`, which is an `inline`
  Content-Disposition with a proper filename, so "Save as" still offers
  something readable;
* the **link** carries `target="_blank" rel="noopener"`, so it opens beside the
  page it came from rather than replacing it.

`SpaNavigationHtmlTest` asserts both: every route in `GENERATED_DOCUMENT_ROUTES`
must be linked with `target="_blank"`, and no controller that loads a PDF view
may call `->download()`. `GeneratedDocumentTest` then fetches each of them and
asserts the response really is `inline`.

**Uploaded files are deliberately not in this.** A patient document or an order
attachment is whatever somebody attached, and serving arbitrary uploads inline
from our own origin is an XSS vector. Those stay downloads
(`admin.patients.documents.download`, `admin.orders.attachments.download`).

## 2. A document says who printed it

Each template used to write its own header — `$hospital?->name` and
`$hospital?->address`, at whatever size that template happened to use — so seven
documents from one hospital looked like seven documents from seven hospitals,
and not one of them carried a number anybody could ring about it.

There is now one letterhead.

| Piece | Where |
|---|---|
| The details | `App\Support\DocumentBrand` |
| The page itself — letterhead, type scale, footer | `<x-pdf.document>` |
| What an admin fills in | `App\Livewire\Settings\Hospital` → **Settings · Hospital letterhead** |

`DocumentBrand::resolve()` returns the name, address, phone, email, website,
licence line, footer and logo. Every template renders `<x-pdf.document>` from
it, so setting a logo sets it on all of them at once, and adding a line here
adds it to all of them at once.

### Where the details live

`name`, `address` and `logo` are columns on `hospitals` — `logo` has been there
since the first migration with nothing able to set it and nothing reading it.
The contact lines live in `hospitals.settings['profile']`: five columns nothing
queries, joins or sorts by would be five migrations for five strings that are
only ever printed.

The footer is `settings['billing']['invoice_footer']` — it was already a billing
setting, and the letterhead page writes into the same key rather than opening a
second copy of it that could disagree.

### The logo is embedded, not linked

DomPDF runs with `enable_remote = false` and a chroot on the project root, so a
URL fetches nothing and a path outside the root is refused. `DocumentBrand`
reads the file off the public disk and inlines it as a base64 data URI, which is
neither. It refuses anything that is not a PNG, JPEG or GIF, caps it at 512 KB,
and returns null on any failure at all — a hospital whose logo file was deleted
from under it still gets an invoice.

`AvatarService::storeLogo()` fits an upload inside 600×240 **without cropping**
and stores it as PNG. Squaring a logo, the way an avatar is squared, crops the
wording off half the logos in the world; PNG because a transparent background
has to stay transparent on a white sheet.

### Typography

DomPDF can embed DejaVu Sans, DejaVu Serif and DejaVu Sans Mono and nothing else
without font files committed to the repo. That is not worth working around: what
makes a document look considered is the scale and the spacing, not the family.
So `<x-pdf.document>` sets one family for text, one for figures, a fixed type
scale, and a single accent colour — and **every number in every document is set
in the mono face and right-aligned**, so columns of money line up on the decimal
point wherever they appear.

The letterhead and the footer are `position: fixed`, so a document that runs to
three pages carries its identity and its page number on all three. Page numbers
use DomPDF's CSS counters; `enable_php` stays off, because drawing a page number
is not worth handing a template an `eval()`.

## The documents

| Document | Route | Template |
|---|---|---|
| Invoice | `admin.invoices.pdf` | `pdf/invoice` |
| Receipt | `admin.payments.receipt` | `pdf/receipt` |
| Laboratory report | `admin.lab-orders.pdf` | `pdf/lab-result` |
| Radiology report | `admin.radiology-orders.pdf` | `pdf/radiology-report` |
| Discharge summary | `admin.admissions.summary` | `pdf/discharge-summary` |
| Insurance usage statement | `admin.insurance-providers.usage` | `pdf/insurance-usage` |
| Patient ID card | `admin.patients.id-card` | `pdf/patient-card` |
| **Full visit report** | `admin.visits.report` | `pdf/visit-report` |

### The full visit report

The one that has to stand entirely on its own. It is what a patient is handed
when they are sent somewhere else, and whoever reads it next has none of this
system: no login, no history, no way to ask a follow-up question.

So it is ordered the way that clinician will read it, not the way the database
is filed:

1. **Who this is, and what would harm them** — identity, next of kin, and then
   allergies, ongoing conditions and blood group, boxed, before anything else.
   An allergy printed under the billing section is an allergy nobody sees.
2. **Why they came** — reason, complaints, the patient's own words, vitals.
3. **What was found** — diagnosis and the clinician's remarks.
4. **What was done** — every order, its items and the report written on it.
5. **What came back** — lab figures set against their reference ranges, with
   anything out of range in red; radiology findings and impression.
6. **What they are taking** — prescriptions with doses and instructions, and
   what was actually dispensed.
7. **The stay**, if there was one.
8. **The account**, last, because it is the least of what a clinician needs.

Then a named preparer and a signature rule.

Three rules it keeps:

* **A reading nobody took is not printed.** A row of dashes costs the reader
  the time to work out that it says nothing.
* **"None recorded" is printed where it applies.** A blank space cannot say
  whether a question was asked and answered no, or never asked.
* **Age is the age at the time of the visit**, not age today. A report is a
  record of a day, and a four-year-old report that ages with the patient is
  quietly wrong.

A PDF cannot carry an X-ray image, so the report names the files held against
the visit and says they may be requested from the issuing facility, rather than
implying there was nothing.

`VisitReportService` gathers it — eagerly, in one pass — and the template only
prints. The account figures come from `BillingService`, so the report can never
quote a total the bill panel would not.

The ID card is the one exception to the page furniture: it is a physical card
(297×210pt), not a sheet of A4, so it keeps its own layout — but it carries the
same logo and the same hospital name as everything else.

A clinical report — lab, radiology, discharge — ends with a **named signatory and
a signature rule**. A result nobody has signed is not a result anybody should
act on, and the document now says so rather than implying it.

## Setting it up

**Settings · Hospital letterhead** (`manage-settings`). It carries a live preview
of the real letterhead at the real proportions, because a logo checked at 300px
looks nothing like the same logo at 46px, and the first anyone knew about that
used to be a printed invoice. Replacing a logo deletes the one it replaced.

## Tests

`VisitReportTest` — the allergy leads the page; a patient with none says so; the
age is the age at the time; only the vitals that were taken appear; a cancelled
line is not reported as work done; an out-of-range result is set apart;
prescriptions carry their doses; an admission carries its length and notes; an
uninvoiced visit shows the live bill and an invoiced one the frozen figures;
another hospital's visit 404s; an empty visit still prints.

`GeneratedDocumentTest` — every document renders and every one is `inline`; the
hospital's details reach the page; a bare hospital still prints; the logo is
embedded, a missing file is ignored, an SVG is refused; the settings page saves,
replaces and removes; a role without `manage-settings` is refused.

`SpaNavigationHtmlTest` — the links open in a tab and no controller attaches a
generated PDF.

## One store for the hospital's own details

There were two. Setup wrote `hospitals.settings['contact']`; the letterhead page
wrote `settings['profile']`, which is what `DocumentBrand` prints. So a hospital
could answer either one and be wrong — fill in setup and every invoice went out
with no way to ring anybody, fill in the letterhead and the setup step holding
the admin in the wizard stayed unticked. It was live: a hospital with a phone
number and an email entered during onboarding was printing neither.

`profile` is the one store now. `OnboardingStatus::contact()` reads it with the
legacy `contact` key underneath, so hospitals set up before the merge keep what
they already gave; `DocumentBrand::profileOf()` goes through the same method, so
there is one reader. Both writers merge rather than replace — setup owns phone
and email, the letterhead page also owns website and licence number, and neither
may wipe the other's.

### The logo is asked for during setup

It lived only on the letterhead page, which a new hospital has no reason to
visit, so the first invoice they ever sent went out plain and nothing had asked.
The onboarding profile step now takes it, and a hospital without one is flagged
on the checklist — as an issue, never a blocker, because a hospital without a
logo is still a hospital.

Both places use `<x-ui.drop-file>`: the same `<input type="file">` the browser
validates, with the whole area as a drop target and the preview drawn inside it.
Dragging a logo onto the page is what people try first, and it used to do
nothing. The preview is guarded by `isPreviewable()` — `temporaryUrl()` throws
on anything Livewire will not draw, so a dropped PDF took the page down with an
exception instead of showing the validation error waiting for it.
