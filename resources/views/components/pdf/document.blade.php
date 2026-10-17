@props([
    'title',                 // what this document is: INVOICE, LAB REPORT…
    'reference' => null,     // its number, if it has one
    'meta' => [],            // label => value, printed under the reference
    'accent' => null,        // the one colour the document uses: the hospital's own, else teal
    'patient' => null,       // who it is about: only their initials reach the verification page
])
@php($brand = app(\App\Support\DocumentBrand::class)->resolve())
{{-- The verification QR (roadmap 05 §4): a short code and a QR that open /v/{code},
     which confirms the document is genuine without showing what it says. --}}
@php($verification = app(\App\Services\DocumentVerifier::class)->stamp($title, $reference, $patient))
@php($accent = $accent ?? $brand['accent'] ?? '#0e6b63')
{{--
  Every PDF this system prints, from the outside in.

  Seven templates each used to write their own header — a different font size, a
  different rule, a different amount of the hospital's own identity — so one
  hospital's documents did not look like each other's. This is the letterhead,
  the type scale and the page furniture, once.

  TYPOGRAPHY. DomPDF ships DejaVu Sans, DejaVu Serif and DejaVu Sans Mono and
  can embed nothing else without font files in the repo. That is not a
  limitation worth working around: what makes a document look considered is the
  SCALE and the SPACING, not the family. So there is one family for text, one
  for figures, a fixed scale, and a single accent colour — and every number in
  every document is set in the mono face, right-aligned, so columns of money
  line up on the decimal point wherever they appear.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{{ $reference ? $reference.' · '.$title : $title }}</title>
  <style>
    /* Room for a letterhead of up to four lines: a clinic with an address, two phone
       numbers, an email and a website wraps to three, and the rule under it must
       not land on the first row of the page. */
    @page { margin: 134px 40px 76px 40px; }

    * { box-sizing: border-box; }

    body {
      font-family: "DejaVu Sans", sans-serif;
      font-size: 10.5px;
      line-height: 1.5;
      color: #1b2733;
      margin: 0;
    }

    /* ── The letterhead, repeated on every page ───────────────────── */
    /* Fixed, so a document that runs to three pages carries its own
       identity on all three rather than only on the first. */
    header.sheet-head {
      position: fixed;
      top: -110px; left: 0; right: 0; height: 96px;
    }

    footer.sheet-foot {
      position: fixed;
      bottom: -56px; left: 0; right: 0; height: 44px;
      border-top: .5px solid #e3e8ec;
      padding-top: 7px;
      color: #9aa7b1;
      font-size: 7.5px;
      line-height: 1.45;
    }

    .head-grid { width: 100%; border-collapse: collapse; }
    .head-grid td { vertical-align: top; padding: 0; border: none; }

    /* Both sides auto with limits: the logo keeps its proportions whatever its
       shape (a fixed height with a max-width squashes a long logo). */
    .logo { width: auto; height: auto; max-height: 46px; max-width: 150px; }
    /* A wide logo carries the name in it, so it gets the room to be read. */
    .logo.wide { max-height: 54px; max-width: 210px; }

    .org-name {
      font-size: 15px;
      font-weight: bold;
      letter-spacing: -.2px;
      color: {{ $accent }};
      line-height: 1.2;
    }

    .org-tagline { font-size: 8px; font-style: italic; color: {{ $accent }}; margin-top: 1px; }

    .org-lines {
      font-size: 7.8px;
      color: #6b7a86;
      line-height: 1.5;
      margin-top: 2px;
    }

    /* The right-hand block: what this document IS, and which one it is. */
    .doc-title {
      font-size: 12.5px;
      font-weight: bold;
      letter-spacing: 1.6px;
      text-transform: uppercase;
      color: #1b2733;
      line-height: 1.2;
    }

    .doc-ref {
      font-family: "DejaVu Sans Mono", monospace;
      font-size: 10px;
      color: {{ $accent }};
      margin-top: 3px;
    }

    .doc-meta { font-size: 7.8px; color: #6b7a86; margin-top: 2px; line-height: 1.5; }
    .doc-meta b { color: #1b2733; font-weight: normal; }

    .rule { border-bottom: 1.5px solid {{ $accent }}; margin-top: 9px; }

    /* ── Body ──────────────────────────────────────────────────────── */
    h2 {
      font-size: 9px;
      font-weight: bold;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      color: #6b7a86;
      margin: 20px 0 7px;
      padding-bottom: 4px;
      border-bottom: .5px solid #e3e8ec;
    }
    h2:first-of-type { margin-top: 4px; }

    p { margin: 0 0 8px; }
    .muted { color: #6b7a86; }
    .small { font-size: 8.5px; }
    .num { font-family: "DejaVu Sans Mono", monospace; }

    /* A block of label/value pairs — who this document is about. */
    .party { width: 100%; border-collapse: collapse; }
    .party td { vertical-align: top; padding: 0 14px 0 0; border: none; width: 33%; }
    .party .k {
      display: block;
      font-size: 7.5px;
      letter-spacing: .9px;
      text-transform: uppercase;
      color: #9aa7b1;
      margin-bottom: 1px;
    }
    .party .v { font-size: 10.5px; }
    .party .v.big { font-size: 12px; font-weight: bold; }

    /* ── Tables ────────────────────────────────────────────────────── */
    table.rows { width: 100%; border-collapse: collapse; margin-top: 4px; }

    table.rows th {
      text-align: left;
      font-size: 7.5px;
      font-weight: bold;
      letter-spacing: .9px;
      text-transform: uppercase;
      color: #6b7a86;
      padding: 0 7px 5px;
      border-bottom: 1px solid #cfd8de;
    }

    table.rows td {
      padding: 6px 7px;
      border-bottom: .5px solid #eef2f4;
      vertical-align: top;
    }

    table.rows tr td:first-child, table.rows tr th:first-child { padding-left: 0; }
    table.rows tr td:last-child, table.rows tr th:last-child { padding-right: 0; }

    .r { text-align: right; }
    td.r, th.r { text-align: right; }
    td.num, th.num { font-family: "DejaVu Sans Mono", monospace; }

    /* Totals hang off the right, as they do on every invoice ever sent. */
    table.sums { width: 46%; border-collapse: collapse; margin: 10px 0 0 54%; }
    table.sums td { padding: 3px 0; border: none; font-size: 10px; }
    table.sums td.r { font-family: "DejaVu Sans Mono", monospace; }
    table.sums tr.grand td {
      border-top: 1.5px solid {{ $accent }};
      padding-top: 6px;
      font-size: 12.5px;
      font-weight: bold;
      color: {{ $accent }};
    }

    /* One figure, said loudly — a receipt's amount, a card's balance. */
    .headline {
      font-family: "DejaVu Sans Mono", monospace;
      font-size: 24px;
      font-weight: bold;
      color: {{ $accent }};
      letter-spacing: -.5px;
    }

    .note {
      border-left: 2px solid #e3e8ec;
      padding: 1px 0 1px 10px;
      color: #46586a;
    }

    .stamp {
      display: inline-block;
      border: 1px solid;
      padding: 1px 7px;
      font-size: 8px;
      font-weight: bold;
      letter-spacing: 1px;
      text-transform: uppercase;
    }
    .stamp.ok   { color: #1d7a4c; border-color: #1d7a4c; }
    .stamp.warn { color: #9a6b00; border-color: #9a6b00; }
    .stamp.bad  { color: #b3261e; border-color: #b3261e; }

    .foot-note { margin-top: 22px; }

    /* The tooth chart of a dental case sheet (pdf/partials/dental-chart). */
    table.odonto-pdf { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    table.odonto-pdf td { border: .5px solid #cfd8de; text-align: center; vertical-align: top; padding: 2px 1px; font-size: 7.5px; height: 24px; }
    table.odonto-pdf td .n { font-family: "DejaVu Sans Mono", monospace; color: #6b7a86; font-size: 7px; }
    table.odonto-pdf td.on { border: 1px solid #1b2733; font-weight: bold; }
    table.odonto-pdf td.mid { border: none; width: 6px; }
    table.odonto-pdf td.gap { border: none; }
    h3.sub { font-size: 9px; text-transform: uppercase; letter-spacing: .6px; color: #6b7a86; margin: 10px 0 2px; }

    /* DomPDF's own page counters. `enable_php` stays off — drawing a page
       number is not worth handing a template an eval(). */
    .pagenum:after { content: counter(page); } /* dompdf cannot count the pages: "of" printed "of 0" */

    /* The verification QR, bottom right of every page. */
    .verify-qr { width: 40px; height: 40px; }
    .verify-code { font-family: "DejaVu Sans Mono", monospace; color: #1b2733; }
  </style>
</head>
<body>

<header class="sheet-head">
  <table class="head-grid">
    <tr>
      {{-- Left: who printed this. --}}
      <td style="width:62%;">
        <table class="head-grid">
          <tr>
            @if($brand['logo'])
              <td style="width:1%; padding-right:12px;">
                <img src="{{ $brand['logo'] }}" alt="" @class(['logo', 'wide' => $brand['wide']])>
              </td>
            @endif
            <td>
              <div class="org-name">{{ $brand['name'] }}</div>
              @if($brand['tagline'])<div class="org-tagline">{{ $brand['tagline'] }}</div>@endif
              <div class="org-lines">
                @if($brand['address']){{ $brand['address'] }}<br>@endif
                @php($contact = array_filter([$brand['phone'], $brand['email'], $brand['website']]))
                @if($contact){{ implode('  ·  ', $contact) }}<br>@endif
                @if($brand['registration']){{ $brand['registration'] }}@endif
              </div>
            </td>
          </tr>
        </table>
      </td>

      {{-- Right: what this is, and which one. --}}
      <td style="width:38%; text-align:right;">
        <div class="doc-title">{{ $title }}</div>
        @if($reference)<div class="doc-ref">{{ $reference }}</div>@endif
        @if($meta)
          <div class="doc-meta">
            @foreach($meta as $label => $value)
              @continue($value === null || $value === '')
              {{ $label }} <b>{{ $value }}</b>@if(! $loop->last)<br>@endif
            @endforeach
          </div>
        @endif
      </td>
    </tr>
  </table>
  <div class="rule"></div>
</header>

<footer class="sheet-foot">
  <table class="head-grid">
    <tr>
      <td style="width:{{ $verification ? '56' : '70' }}%;">
        @if($brand['footer']){{ $brand['footer'] }}<br>@endif
        {{ $brand['name'] }}@if(app(\App\Support\DocumentBrand::class)->oneLine())  ·  {{ app(\App\Support\DocumentBrand::class)->oneLine() }}@endif
      </td>
      <td style="width:{{ $verification ? '34' : '30' }}%; text-align:right;">
        Generated {{ now()->format('d M Y H:i') }}<br>
        Page <span class="pagenum"></span>
        @if($verification)
          <br>Verify at {{ preg_replace('#^https?://#', '', route('verify.form')) }} · <span class="verify-code">{{ $verification['display'] }}</span>
        @endif
      </td>
      @if($verification)
        <td style="width:10%; text-align:right;">
          <img class="verify-qr" src="{{ $verification['qr'] }}" alt="Verification code {{ $verification['display'] }}">
        </td>
      @endif
    </tr>
  </table>
</footer>

<main>{{ $slot }}</main>
</body>
</html>
