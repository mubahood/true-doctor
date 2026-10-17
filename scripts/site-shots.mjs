// Real screenshots of the product for the public site (roadmap 02 §3), taken
// from the demo hospital (Kato Medical Centre) as each role sees it.
//
//   PLAYWRIGHT_DIR=/path/to/node_modules node scripts/site-shots.mjs [name ...]
//
// Signs in through the sign-in form as the role each shot needs, captures at
// 2x, converts to WebP with cwebp (at most 150 KB each) into
// public/images/site/shots/, and writes manifest.json there:
// { name: { w, h, kb } }, which the pages read for width and height.
//
// Env: BASE (default http://localhost:8888/true-doctor), PASS (password1),
// TARGETS (JSON: {"dental_patient": uuid, "visit": uuid}), CWEBP, PLAYWRIGHT_DIR.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OUT = path.join(ROOT, 'public/images/site/shots');
const BASE = (process.env.BASE || 'http://localhost:8888/true-doctor').replace(/\/$/, '');
const PASS = process.env.PASS || 'password1';
const TARGETS = JSON.parse(process.env.TARGETS || '{}');
const MAX_KB = 150;

function loadPlaywright() {
  for (const dir of [process.env.PLAYWRIGHT_DIR, path.join(ROOT, 'node_modules')].filter(Boolean)) {
    try { return createRequire(path.join(dir, 'noop.js'))('playwright'); } catch { /* next */ }
  }
  console.error('Playwright not found. Run with PLAYWRIGHT_DIR=/path/to/node_modules');
  process.exit(1);
}

function findCwebp() {
  for (const c of [process.env.CWEBP, '/opt/homebrew/bin/cwebp', '/usr/local/bin/cwebp', 'cwebp'].filter(Boolean)) {
    try { execFileSync(c, ['-version'], { stdio: 'ignore' }); return c; } catch { /* next */ }
  }
  return null;
}

/** Pixel size of a PNG or WebP (from its header). */
function dims(file) {
  const b = fs.readFileSync(file);
  if (b.toString('ascii', 1, 4) === 'PNG') return { w: b.readUInt32BE(16), h: b.readUInt32BE(20) };
  const chunk = b.toString('ascii', 12, 16);
  if (chunk === 'VP8X') return { w: 1 + b.readUIntLE(24, 3), h: 1 + b.readUIntLE(27, 3) };
  if (chunk === 'VP8 ') return { w: b.readUInt16LE(26) & 0x3fff, h: b.readUInt16LE(28) & 0x3fff };
  if (chunk === 'VP8L') { const v = b.readUInt32LE(21); return { w: 1 + (v & 0x3fff), h: 1 + ((v >> 14) & 0x3fff) }; }
  throw new Error('unknown image format: ' + file);
}

// What never belongs in a picture on the home page.
const HIDE_CSS = `
  .phpdebugbar, [class*="phpdebugbar"], #debugbar, .env, [data-env-badge], .wa-float,
  .tb-demo-cta, .tb-demo-banner, [data-demo-banner],
  .tb-toasts, .tb-toast, [x-data="tdClock"] { display: none !important; }
  *, *::before, *::after { transition: none !important; animation: none !important; caret-color: transparent !important; }
`;

// name: [email, path, viewport, options]. Options: width (the big file's
// width, default 1600), small (false: no name-800.webp for phones) and
// crop ('content': leave the sidebar out).
const SHOTS = {
  // The hero: the whole window, sidebar and all.
  dashboard: ['admin.a@test.com', '/admin', { width: 1280, height: 800 }],
  // The day timeline: the working area only (crop), from a narrower window,
  // so the words are big enough to read at the size the page draws them.
  reception: ['reception.a@test.com', '/admin/visits', { width: 1280, height: 760 }, { crop: 'content' }],
  visit: ['doctor.a@test.com', TARGETS.visit ? `/admin/visits/${TARGETS.visit}` : '/admin/visits', { width: 1100, height: 800 }, { crop: 'content' }],
  lab: ['lab.a@test.com', '/admin/lab-orders', { width: 1100, height: 720 }, { crop: 'content' }],
  cashier: ['accounts.a@test.com', '/admin/invoices', { width: 1280, height: 760 }, { crop: 'content' }],
  ward: ['nurse.a@test.com', '/admin/occupancy', { width: 1100, height: 760 }, { crop: 'content' }],
  dental: ['doctor.a@test.com', TARGETS.dental_patient ? `/admin/patients/${TARGETS.dental_patient}?tab=dental` : '/admin/patients', { width: 1100, height: 840 }, { crop: 'content' }],
  closing: ['accounts.a@test.com', TARGETS.closing_date ? `/admin/cashier/day?date=${TARGETS.closing_date}` : '/admin/cashier/day', { width: 1100, height: 600 }, { crop: 'content' }],
  // The same visit on a phone: the panel is the same app at any width.
  // 480 wide: the hero draws the phone about 200px across.
  phone: ['doctor.a@test.com', TARGETS.visit ? `/admin/visits/${TARGETS.visit}` : '/admin/visits', { width: 390, height: 844 }, { width: 480, small: false }],
};

/** cwebp at falling quality until the file is under the budget. */
function toWebp(cwebp, png, file, width, maxKb) {
  for (const q of [80, 70, 60, 50, 40]) {
    execFileSync(cwebp, ['-quiet', '-q', String(q), '-resize', String(width), '0', png, '-o', file]);
    if (fs.statSync(file).size <= maxKb * 1024) break;
  }
  const { w, h } = dims(file);
  return { file: path.basename(file), w, h, kb: Math.round(fs.statSync(file).size / 1024) };
}

async function signIn(context, email) {
  const page = await context.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}), page.click('button[type="submit"]')]);
  return page;
}

async function main() {
  const { chromium } = loadPlaywright();
  const cwebp = findCwebp();
  const wanted = process.argv.slice(2);
  fs.mkdirSync(OUT, { recursive: true });
  const manifestFile = path.join(OUT, 'manifest.json');
  const manifest = fs.existsSync(manifestFile) ? JSON.parse(fs.readFileSync(manifestFile, 'utf8')) : {};
  // The installed Chrome when Playwright's own browser is missing or a different version.
  const chrome = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
  const browser = await chromium.launch(fs.existsSync(chrome) ? { executablePath: chrome } : {});

  try {
    for (const [name, [email, target, viewport, options = {}]] of Object.entries(SHOTS)) {
      if (wanted.length && !wanted.includes(name)) continue;
      const context = await browser.newContext({ viewport, deviceScaleFactor: 2 });
      const page = await signIn(context, email);
      await page.goto(BASE + target, { waitUntil: 'networkidle' });
      await page.addStyleTag({ content: HIDE_CSS });
      await page.waitForTimeout(900);
      await page.evaluate(() => document.activeElement?.blur?.());
      const png = path.join(os.tmpdir(), `td-shot-${name}.png`);
      let clip;
      if (options.crop === 'content') {
        const side = await page.locator('.tb-sidebar').boundingBox();
        if (side) clip = { x: side.width, y: 0, width: viewport.width - side.width, height: viewport.height };
      }
      await page.screenshot({ path: png, clip });
      await context.close();

      if (cwebp) {
        manifest[name] = toWebp(cwebp, png, path.join(OUT, `${name}.webp`), options.width ?? 1600, MAX_KB);
        // A phone is sent the 800-wide copy (srcset), a third of the bytes.
        if (options.small !== false) {
          manifest[name].small = toWebp(cwebp, png, path.join(OUT, `${name}-800.webp`), 800, 60);
        }
      } else {
        const file = path.join(OUT, `${name}.png`);
        fs.copyFileSync(png, file);
        const { w, h } = dims(file);
        manifest[name] = { file: path.basename(file), w, h, kb: Math.round(fs.statSync(file).size / 1024) };
      }
      const m = manifest[name];
      console.log(`${name}: ${m.file} ${m.w}x${m.h} ${m.kb} KB${m.small ? `, ${m.small.file} ${m.small.kb} KB` : ''}`);
    }
  } finally {
    await browser.close();
  }

  fs.writeFileSync(manifestFile, JSON.stringify(manifest, null, 2) + '\n');
}

main().catch((e) => { console.error(e); process.exit(1); });
