# 03. WhatsApp and support everywhere

**The number:** **+256 783 204 665**. The digits for links are `256783204665`.

**The rule:** stored once, read everywhere, never typed into a view, an email, a PDF or a Dart file.

**Why it matters:** in Uganda the first question a clinic owner asks is "who do I call?". Today the answer on the site, in the back office and in the app is nothing. The contact form sends enquiries to `noreply@`, and the app tells a user to "paste diagnostics to whoever supports you". Every surface below must answer that question in one tap.

---

## 1. One source of truth (P0)

**Platform default** (SaaS and the vendor of every copy):

- Settings, editable by the super admin: `contact_whatsapp`, `contact_phone`, `contact_email`, `support_hours`.
- `contact_phone` and `contact_email` already exist in `app/Livewire/Settings/Site.php`. Add `contact_whatsapp` and `support_hours`.
- Env fallbacks when a setting is empty:
  - `SUPPORT_WHATSAPP=256783204665`
  - `SUPPORT_PHONE="+256 783 204 665"`
  - `SUPPORT_EMAIL=support@true-doctor.online`
  - `SUPPORT_HOURS="Monday to Saturday, 8:00 to 20:00"`
- One reader: a new `App\Support\SupportContact` with:
  - `whatsappDigits()`, `whatsappDisplay()`, `phone()`, `email()`, `hours()`;
  - `waLink(string $context, array $vars = [])`, which builds `https://wa.me/256783204665?text=…` from a named template;
  - `forHospital(?Hospital $h)`, which applies the standalone and partner rules below.
- Cache the settings read once per request, the same way `HospitalBrand` does.

**Who supports a standalone copy, in order:**

1. The licence's `support` block (a partner who sold and installed it).
2. The organisation's own IT contact (Settings → Hospital, new optional fields).
3. The platform default (us).

The buyer can always see who supports them.

**Patient-facing documents are the hospital's, not ours.** Invoices, receipts, results and cards keep the hospital's own phone and address (`HospitalBrand::contact()`). Our line appears only as a small "System by True-Doctor · WhatsApp +256 783 204 665" footer:

- on by default on SaaS;
- off by default on standalone;
- switchable per hospital in Settings → Documents.

This keeps clients' brands intact and still spreads the name.

**Never in a prefilled message:** a patient's name, number, diagnosis or any record content. Context carries only:

- the page or screen name;
- the hospital name and the person's role;
- an error reference or a release version.

## 2. Prefilled messages, one per context

Kept in `lang/en/support.php` (and `lg`, `sw` later), so the words are edited in one place:

| Context key | Message |
|---|---|
| `site.general` | Hello True-Doctor, I found you on your website and I would like to know more. |
| `site.page` | Hello True-Doctor, I am reading your page about :page and I have a question. |
| `site.pricing` | Hello True-Doctor, I would like help choosing a plan for my :practice. |
| `site.demo` | Hello True-Doctor, I opened the demo and I would like a walkthrough. |
| `site.own_copy` | Hello True-Doctor, I am interested in buying our own copy of True-Doctor. |
| `auth.signin` | Hello True-Doctor, I cannot sign in to :hospital. |
| `trial.welcome` | Hello True-Doctor, I have just started a trial for :hospital. Please help me set up. |
| `admin.help` | Hello True-Doctor, I am :role at :hospital and I need help with :screen. |
| `admin.error` | Hello True-Doctor, I got an error at :hospital. Reference :ref. |
| `admin.limit` | Hello True-Doctor, :hospital has reached the :limit limit on our plan. |
| `admin.renew` | Hello True-Doctor, I would like to renew the subscription for :hospital. |
| `app.help` | Hello True-Doctor, I need help with the app (version :version, :role at :hospital). |
| `app.diagnostics` | Hello True-Doctor, here is my app diagnostics report: :report |
| `standalone.install` | Hello True-Doctor, I need help installing our copy (licence :licence). |
| `standalone.update` | Hello True-Doctor, our copy :version needs help with an update. |

## 3. Every surface

Each row is built, tested and ticked.

### Public site (`layouts/marketing.blade.php` and pages)

| Surface | What shows | Tag |
|---|---|---|
| Header | "WhatsApp us" button beside "Start free" (desktop); in the mobile menu at the top | P0 |
| Floating button | A round WhatsApp button, bottom right, above the safe area. Hidden while printing, never covering a form's submit button, `aria-label="Chat with True-Doctor on WhatsApp"` | P0 |
| Footer | WhatsApp link, phone as a `tel:` link, email, support hours, company facts | P0 |
| Contact page | WhatsApp first (big), then phone, then email, then the form. Form enquiries go to the real inbox | P0 |
| Pricing | Under every plan: "Not sure? WhatsApp us". Enterprise and the one-off copy: "Talk to us" opens WhatsApp | P0 |
| Demo and walkthrough | "Book a walkthrough" opens WhatsApp with `site.demo` | P0 |
| Every practice, module, country and guide page | Closing line with `site.page` naming the page | P1 |
| Error pages (`resources/views/errors/*`) | "Something went wrong. WhatsApp us with this reference: :ref" | P0 |
| JSON-LD | `Organization.contactPoint` with the phone, `contactType: customer support`, `availableLanguage: en, lg, sw` | P1 |

### Sign-in and sign-up (`layouts/auth.blade.php`, `auth/*`)

| Surface | What shows | Tag |
|---|---|---|
| Sign-in | "Trouble signing in? WhatsApp us" (`auth.signin`) | P0 |
| Forgot and reset password | Same line | P0 |
| Register | WhatsApp field for the owner (required, with country code) plus "Questions before you start? WhatsApp us" | P0 |

### Back office (Livewire shell)

| Surface | What shows | Tag |
|---|---|---|
| Top bar | A "Help" menu: WhatsApp support (`admin.help` with the current screen), call us, email us, the guide for this screen, what's new | P0 |
| Command palette | "Contact support" action | P1 |
| Onboarding wizard | Each step: "Stuck? WhatsApp us" | P0 |
| Trial banner | Days left, plus "Renew" and "WhatsApp us" | P0 |
| Plan limit reached | The limit message offers upgrade and `admin.limit` | P0 |
| Subscription page and `errors/subscription-lapsed` | Vendor contact with `admin.renew` (today it only says "ask your administrator") | P0 |
| Unexpected error toast | "Send to support" opens WhatsApp with the reference id (`admin.error`) | P1 |
| Empty states | First patient, first visit, first invoice: a "Need a hand?" link | P1 |
| Settings → About | Who supports this copy (§1), version, licence | P1 |

### Emails (`app/Notifications/*`, `app/Mail/*`)

| Surface | What shows | Tag |
|---|---|---|
| Every vendor email (welcome, verify, reset, trial ending, subscription activated, enquiry received, standalone download ready) | A footer with WhatsApp, phone, email and hours from `SupportContact` | P0 |
| The welcome email | A "WhatsApp us to set up" button (`trial.welcome`) | P0 |

### Documents (`resources/views/pdf/*`)

| Surface | What shows | Tag |
|---|---|---|
| Invoice, receipt, lab, radiology, discharge, card, statement | The hospital's contact (as today), plus the optional "System by True-Doctor" footer (§1) | P1 |
| SOLAVIA's own invoices to clients | Full company facts and the WhatsApp number | P0 |

### API and the app

| Surface | What shows | Tag |
|---|---|---|
| `GET /api/v1/meta` (`MetaController`) | A `support` block with `whatsapp`, `phone`, `email` and `hours`; `who` (platform, partner or own IT); and the prefilled `templates` with their placeholders | P0 |
| `GET /api/v1/public/plans` | The same `support` block, so the app's signed-out landing has it | P0 |
| App landing and sign-in (`mobo/lib/features/auth/*`) | "Talk to us on WhatsApp" | P0 |
| App account page (`mobo/lib/features/account/account_page.dart`) | A "Get help" card: WhatsApp, call, email | P0 |
| App diagnostics | "Send to support on WhatsApp" with the diagnostics text, which already has no names, phones or money | P0 |
| Update, subscription-ended and web-only screens | The support line with the right template | P1 |
| Demo ribbon | "Talk to us" opens `site.demo` | P1 |
| Play Store listing | Contact email and phone; WhatsApp in the description | P0 |

### Standalone edition

| Surface | What shows | Tag |
|---|---|---|
| `public/install.php` and `INSTALL.md` | WhatsApp for installation help (`standalone.install`) | P0 |
| `/my-licence` portal and licence emails | WhatsApp line | P0 |
| Settings → Licence & updates | Who supports this copy, with `standalone.update` | P1 |

### Off the product

- Google Business Profile, YouTube channel and video descriptions, social profiles, proposals, business cards and the email signature all carry the same number (P1).

## 4. Measuring it (P1)

- Every WhatsApp link goes through `GET /go/whatsapp?c=<context>&p=<page>`. It records a `whatsapp_click` in `TrafficRecorder` and answers 302 to `wa.me`.
- The plain `wa.me` link stays in the HTML as a fallback when the redirect is blocked.
- `/super/traffic` shows clicks by page, context and source next to trials and payments.

## 5. Tests

- **`SupportContact` unit tests:**
  - digits and display formats;
  - template filling;
  - the standalone order (licence → own IT → platform);
  - no record data in any template.
- **`PublicSiteTest`:** every public page has the WhatsApp link, the footer facts and no `noreply@`.
- **Back office:** the Help menu renders on every screen for every role; the lapsed page shows the vendor contact.
- **Emails:** every vendor notification renders the footer.
- **API:** `meta` and `public/plans` carry `support`, and the app widget tests show the help card from that block.
- **No hard-coded number:** a scan in `composer ci` fails if `256783204665` or `783 204 665` appears outside the config, env example and this doc.

## 6. Later: WhatsApp as a channel, not just a link (P2)

- Move support to the **WhatsApp Business app** first: quick replies, labels (lead, trial, client, partner) and a catalogue of plans.
- Then the **WhatsApp Cloud API** for:
  - inbound routing (sales vs support);
  - the trial welcome sequence;
  - patient messages (05).

  One provider seam (`app/Services/Channels/*`) carries both patient messages and our own.
