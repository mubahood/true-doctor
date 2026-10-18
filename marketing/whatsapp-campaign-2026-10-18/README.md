# True-Doctor · Software ad campaign

Ten finished PNG posters: five concepts, each in two WhatsApp formats. All artwork is authored as type and vectors, using Inter and the repository's True-Doctor shield. No people or photographic assets appear in this campaign.

## Deliverables

- `broadcast/`: five 1080 × 1350 PNGs for chat and broadcast.
- `status/`: five 1080 × 1920 PNGs for Status.
- `editable-svg/`: ten standalone SVGs with embedded Inter font and logo. These retain editable text and vector shapes; use Inter if an editor ignores embedded fonts.
- `campaign-preview.png`: a comparison of the five broadcast posters.
- `index.html`: a local gallery showing both formats side by side.
- `copy.json`: the exact supplied headline and support copy.
- `build.cjs`: reproducible source for layouts, device mockups, UI and export.
- `QA.json`: dimensions, copy checks, font loading and text-boundary checks for every export.

## Design specification

Blue `#015CC4`, white, near-black `#0F1B2D`, and one orange `#F28C28` accent per poster. Secondary UI hairlines and surfaces are quiet blue-grey. Inter 800 headlines and regular support copy. Common 72 px margins, logo position and footer. The two formats share a 1080 × 1350 composition; Status centres it with a 285 px vertical offset. Its footer background continues to the bottom edge.

Every text element, including mockup UI labels, is inside the Status safe area: **y=250 to y=1669**. The footer text is in that safe area rather than at the bottom edge covered by WhatsApp. No guide lines appear in the delivered PNGs.

## Screens and demo content

Devices and screens are crisp vector **advertising mockups**, simplified for readability. Their visual language, module labels and example patient names refer to the existing product demo screenshots in `public/images/site/shots/`. These are not unaltered screenshots. Cash-up uses the requested illustrative UGX 2,450,000; other figures are illustrative demo values. The exact requested offline chip is included. No live database or private patient records were accessed.

## Rebuild

Run `node marketing/whatsapp-campaign-2026-10-18/build.cjs` from the repository root. The script uses the bundled Playwright and sharp packages and local Chrome. Set `WORKSPACE_NODE_MODULES` and `CHROME_PATH` to override their paths on another computer. It exports all SVGs and PNGs, validates copy and bounds, then rebuilds the gallery and comparison image.

No application code, production services or built application assets are changed. This is a local campaign deliverable; nothing is published or sent.
