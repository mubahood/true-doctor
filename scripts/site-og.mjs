// The picture a link to each public page shows when it is shared on WhatsApp,
// Facebook, X or LinkedIn (og:image, 1200x630), one per page.
//
//   PLAYWRIGHT_DIR=/path/to/node_modules node scripts/site-og.mjs [name ...]
//
// Each picture is the page's own heading, read from the running site so it can
// never drift from the page, beside that page's real screenshot from
// public/images/site/shots/ (scripts/site-shots.mjs). Written as JPEG to
// public/images/og/<name>.jpg, which layouts/marketing picks up by name.
//
// Env: BASE (default http://localhost:8888/true-doctor), CHROME, PLAYWRIGHT_DIR.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OUT = path.join(ROOT, 'public/images/og');
const SHOTS = path.join(ROOT, 'public/images/site/shots');
const BASE = (process.env.BASE || 'http://localhost:8888/true-doctor').replace(/\/$/, '');

// name: [path, screenshot, eyebrow]
const PAGES = {
  home: ['/', 'dashboard', 'Clinic and hospital software'],
  pricing: ['/pricing', 'closing', 'Pricing'],
  'for-dental-clinics': ['/for/dental-clinics', 'dental', 'For dental clinics'],
  'for-private-clinics': ['/for/private-clinics', 'visit', 'For private clinics'],
  'for-maternity-centres': ['/for/maternity-centres', 'ward', 'For maternity centres'],
  'for-hospitals': ['/for/hospitals', 'ward', 'For hospitals'],
  walkthrough: ['/walkthrough', 'visit', 'A free walkthrough'],
  'get-app': ['/get-app', 'visit', 'The Android app'],
  about: ['/about', 'dashboard', 'About us'],
  demo: ['/demo', 'reception', 'The live demo'],
  'own-your-copy': ['/own-your-copy', 'dashboard', 'Your own copy'],
};

function loadPlaywright() {
  for (const dir of [process.env.PLAYWRIGHT_DIR, path.join(ROOT, 'node_modules')].filter(Boolean)) {
    try { return createRequire(path.join(dir, 'noop.js'))('playwright'); } catch { /* next */ }
  }
  console.error('Playwright not found. Run with PLAYWRIGHT_DIR=/path/to/node_modules');
  process.exit(1);
}

const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

function card(title, eyebrow, shot) {
  const font = pathToFileURL(path.join(ROOT, 'public/fonts/inter-latin-wght.woff2')).href;
  const logo = pathToFileURL(path.join(ROOT, 'public/images/logo-icon.png')).href;
  const pic = pathToFileURL(path.join(SHOTS, shot)).href;
  return `<!doctype html><html><head><meta charset="utf-8"><style>
    @font-face{font-family:Inter;font-weight:100 900;src:url('${font}') format('woff2');}
    *{margin:0;padding:0;box-sizing:border-box;}
    body{width:1200px;height:630px;overflow:hidden;font-family:Inter,sans-serif;background:#f5f7fa;color:#111c2e;position:relative;}
    .l{position:absolute;left:64px;top:64px;width:520px;bottom:64px;display:flex;flex-direction:column;}
    .brand{display:flex;align-items:center;gap:12px;font-size:24px;font-weight:600;}
    .brand img{width:40px;height:40px;}
    .eb{margin-top:auto;font-size:15px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:#0a6ebd;}
    h1{margin:14px 0 0;font-size:46px;font-weight:300;letter-spacing:-.025em;line-height:1.12;}
    .url{margin-top:28px;font-size:18px;color:#5a6b80;}
    .r{position:absolute;left:640px;top:70px;width:760px;height:620px;border:1px solid #cbd5e1;background:#fff;
       box-shadow:0 30px 60px -30px rgba(13,27,42,.45);overflow:hidden;}
    .r img{width:100%;display:block;}
    .bar{position:absolute;left:0;top:0;width:8px;height:630px;background:#0a6ebd;}
  </style></head><body><div class="bar"></div>
    <div class="l"><div class="brand"><img src="${logo}" alt="">True-Doctor</div>
      <div class="eb">${esc(eyebrow)}</div><h1>${esc(title)}</h1><div class="url">true-doctor.online</div></div>
    <div class="r"><img src="${pic}" alt=""></div></body></html>`;
}

async function main() {
  const { chromium } = loadPlaywright();
  const manifest = JSON.parse(fs.readFileSync(path.join(SHOTS, 'manifest.json'), 'utf8'));
  const wanted = process.argv.slice(2);
  const chrome = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
  const browser = await chromium.launch(fs.existsSync(chrome) ? { executablePath: chrome } : {});
  fs.mkdirSync(OUT, { recursive: true });

  try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
    for (const [name, [url, shot, eyebrow]] of Object.entries(PAGES)) {
      if (wanted.length && !wanted.includes(name)) continue;
      await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
      // The heading's own words: without the small keyword line some H1s lead with.
      const title = ((await page.locator('h1').first().evaluate((h) => {
        const c = h.cloneNode(true);
        c.querySelectorAll('.h1-kicker').forEach((k) => k.remove());
        return c.textContent;
      })) || '').replace(/\s+/g, ' ').trim();
      const file = manifest[shot]?.file;
      if (!title || !file) { console.warn(`${name}: skipped (no heading or no ${shot} shot)`); continue; }

      const html = path.join(OUT, `.${name}.html`);
      fs.writeFileSync(html, card(title, eyebrow, file));
      await page.goto(pathToFileURL(html).href, { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      const out = path.join(OUT, `${name}.jpg`);
      await page.screenshot({ path: out, type: 'jpeg', quality: 82 });
      fs.unlinkSync(html);
      console.log(`${name}: ${Math.round(fs.statSync(out).size / 1024)} KB  "${title}"`);
    }
  } finally {
    await browser.close();
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
