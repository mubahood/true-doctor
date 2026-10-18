# True-Doctor · Campaign redesign

Five redesigned software ads, each supplied as a finished 1080 × 1350 broadcast PNG and 1080 × 1920 WhatsApp Status PNG. The previous campaign remains in its original directory; this version is self-contained.

## Artwork

1. **Ditch the paper.** A diagonal blue field, torn exercise-book pages and layered patient cards leading into an oversized phone tilted 12°.
2. **No network? No problem.** A centred headline, giant white circle, phone at −12°, laptop at 12°, dotted sync arrows and saved-state card. Orange crossed-out signal icon and the requested small green success ticks.
3. **Stop money leaks.** A right-aligned headline, oversized pale-blue UGX, cash-up laptop at −10°, floating invoice and paid receipt.
4. **Every department. One system.** Six department cards orbit a laptop at −10°, connected by blue lines over a large circle.
5. **Run your clinic smarter.** Staggered headline on full blue, overlapping phone and laptop at −12° and 12°, layered lab and stock cards, orange trial button.

Every poster has the same logo and category-label positions. Each headline has one orange word, followed by one short supporting line. The only ending is **true-doctor.online**. No phone numbers or footer bands are present.

## Files

- `broadcast/`: five PNGs, 1080 × 1350.
- `status/`: five PNGs, 1080 × 1920.
- `editable-svg/`: ten standalone SVGs, embedded Inter and brand mark, editable vector shapes and text.
- `campaign-preview.png`: all five broadcast posters together.
- `index.html`: local gallery showing both formats.
- `copy.json`: exact campaign copy.
- `QA.json`: export dimensions, font loading, copy checks, headline checks and text bounds.
- `build.cjs`: reproducible artwork and renderer.
- `Inter-LICENSE.txt`: licence for the embedded font.

## Production notes

Blue `#015CC4`, white, near-black `#0F1B2D`, orange `#F28C28`. Pale blue is used for background typography and UI surfaces; green appears only on the specifically requested saved-state ticks. Inter 900 headlines, flat vector hardware, unwarped UI and restrained physical shadows. Artwork is authored directly as vectors and typeset text. No generated photographs, people, clip-art or plastic 3D icons.

The product stage fills approximately the lower 60% of the shared campaign composition, with larger device edges allowed to bleed. Status uses the same composition with a 285 px offset and full-bleed backgrounds. All text, including UI labels, stays within **y=250–1669** on Status. Text-safe layout does not constrain decorative backgrounds or device frames.

Screens are simplified advertising mockups based on True-Doctor's demo UI patterns, not unaltered screenshots. Names and figures are illustrative demo content. No private patient records or live database were accessed.

## Rebuild

Run `node marketing/whatsapp-campaign-2026-10-18-v2/build.cjs` from the repository root. It uses bundled Playwright/sharp and local Chrome; `WORKSPACE_NODE_MODULES` and `CHROME_PATH` override runtime locations. Outputs are rendered at native dimensions. Checks fail if exact supplied copy differs, a phone number appears, a headline has the wrong word count, or any text leaves the canvas or Status safe area.

This is an asset-only delivery. Application code and services are unchanged. Nothing is published or sent.
