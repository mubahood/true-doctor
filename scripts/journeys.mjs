// The ten daily journeys on the web back office, measured (roadmap 06 §1,
// results in docs/roadmap/journeys.md).
//
//   PLAYWRIGHT_DIR=/path/to/node_modules node scripts/journeys.mjs [1a 3b ...]
//
// Each journey signs in as the role that does the job (not measured: the
// person is already at their desk), lands on that role's dashboard and then
// does the job the way the screens lead you to, through real clicks and real
// typing. Per journey it records:
//
//   clicks    pointer clicks on buttons, links, pills, list rows, radios and
//             check boxes, plus Enter presses that submit. Moving into a text
//             box to type in it is not counted.
//   fields    values entered: text typed, a date, a number, an option chosen
//             in a select, a radio or a check box set. `required` is how many
//             of those the form refuses to save without.
//   screens   pages seen: the dashboard it starts on, plus every change of
//             page (a full load or a Livewire wire:navigate). Dialogs are
//             counted on their own in `dialogs`.
//   seconds   wall clock from the first action to the confirmation that the
//             job is done (the toast, the new page, the printed day). A machine
//             types instantly, so this is a floor, not what a person takes.
//   dialogs   dialogs opened on the way (a native confirm() counts as a click).
//   requests  requests the browser made to the server in that time (page
//             loads and Livewire round trips): the waiting the screens cost.
//
// Each journey also keeps a step log with timings and notes on anything the
// screens refused or made the script work around.
//
// The journeys run in order and hand on what they make (the patient from 1 is
// the one consulted in 3, whose lab order is resulted in 4, ...). Every run
// uses fresh names, so it can be re-run on the same database. The local
// database is a disposable demo; this script writes to it.
//
// Env: BASE (default http://localhost:8888/true-doctor), PASS (password1),
// CHROME (path to Chrome), HEADED=1 to watch, PLAYWRIGHT_DIR, OUT (the JSON).
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE = (process.env.BASE || 'http://localhost:8888/true-doctor').replace(/\/$/, '');
const PASS = process.env.PASS || 'password1';
const OUT = process.env.OUT
  || '/private/tmp/claude-501/-Applications-MAMP-htdocs-true-doctor/f7cde961-4bd3-4b89-83d9-cd93ee88ee8b/scratchpad/journeys.json';
// What one journey hands the next, kept between runs so one journey can be
// re-run on its own while it is being written.
const STATE_FILE = path.join(path.dirname(OUT), 'journeys-state.json');
const STEP_TIMEOUT = 20000;

const HIDE_CSS = '.phpdebugbar,[class*=phpdebugbar]{display:none!important}';

function loadPlaywright() {
  for (const dir of [process.env.PLAYWRIGHT_DIR, path.join(ROOT, 'node_modules')].filter(Boolean)) {
    try { return createRequire(path.join(dir, 'noop.js'))('playwright'); } catch { /* next */ }
  }
  console.error('Playwright not found. Run with PLAYWRIGHT_DIR=/path/to/node_modules');
  process.exit(1);
}

const ACCOUNTS = {
  admin: 'admin.a@test.com',
  doctor: 'doctor.a@test.com',
  nurse: 'nurse.a@test.com',
  reception: 'reception.a@test.com',
  pharmacy: 'pharmacy.a@test.com',
  lab: 'lab.a@test.com',
  radiology: 'radiology.a@test.com',
  accounts: 'accounts.a@test.com',
  records: 'records.a@test.com',
};

// ── The meter ───────────────────────────────────────────────────────────

/**
 * Wraps one page and counts what a person would do on it. Every action waits
 * for the Livewire round trips it starts, so the next action meets the page
 * a person would meet.
 */
class Meter {
  constructor(page, id, title, role) {
    this.page = page;
    this.id = id;
    this.title = title;
    this.role = role;
    this.notes = [];
    this.extra = {};
    this.clicks = 0;
    this.fields = 0;
    this.required = 0;
    this.dialogs = 0;
    this.requests = 0;
    this.screens = [];
    this.steps = [];
    this.t0 = null;
    this.t1 = null;
    this.pending = new Set();
    this.lastPath = null;

    page.on('request', (r) => {
      if (!this.tracked(r)) return;
      this.pending.add(r);
      if (this.t0 !== null && this.t1 === null) this.requests++;
    });
    page.on('requestfinished', (r) => this.pending.delete(r));
    page.on('requestfailed', (r) => this.pending.delete(r));
    // A native confirm() (wire:confirm): the person reads it and presses OK.
    page.on('dialog', async (d) => {
      if (this.t0 !== null && this.t1 === null) {
        this.clicks++;
        this.notes.push(`native ${d.type()}: "${d.message()}"`);
      }
      await d.accept().catch(() => {});
    });
    page.on('framenavigated', (frame) => {
      if (frame !== page.mainFrame()) return;
      const p = new URL(frame.url()).pathname.replace(/^\/true-doctor/, '') || '/';
      if (this.t0 !== null && !this.t1 && p !== this.lastPath) this.screens.push(p);
      this.lastPath = p;
    });
  }

  tracked(r) {
    const t = r.resourceType();
    return (t === 'fetch' || t === 'xhr' || t === 'document') && r.url().startsWith(BASE);
  }

  /** The page the journey starts on: counted as its first screen. */
  begin() {
    this.lastPath = new URL(this.page.url()).pathname.replace(/^\/true-doctor/, '') || '/';
    this.screens = [this.lastPath];
  }

  tick() { if (this.t0 === null) this.t0 = performance.now(); }

  /** When a step started, for the step log. */
  mark() { return performance.now(); }

  /** One line per step: when it started and how long the app took to answer. */
  log(at, what) {
    const took = ((performance.now() - at) / 1000).toFixed(1);
    this.steps.push(`${((at - this.t0) / 1000).toFixed(1)}s ${what} (${took}s)`);
  }

  /** Wait until no request the app started is in flight, for a quiet moment. */
  async settle(quiet = 80) {
    const until = Date.now() + STEP_TIMEOUT;
    let calm = 0;
    while (Date.now() < until) {
      if (this.pending.size === 0) {
        calm += 25;
        if (calm >= quiet) return;
      } else calm = 0;
      await this.page.waitForTimeout(25);
    }
  }

  loc(target) { return typeof target === 'string' ? this.page.locator(target).first() : target; }

  async click(target, note = '') {
    const l = this.loc(target);
    await l.waitFor({ state: 'visible', timeout: STEP_TIMEOUT });
    this.tick();
    this.clicks++;
    const at = this.mark();
    await l.click();
    await this.settle();
    this.log(at, `click ${note}`.trim());
  }

  /** Click something that opens a dialog. */
  async open(target, note = '') {
    await this.click(target, note);
    this.dialogs++;
  }

  /**
   * Type into a box. A person does not wait for the save a box sends when
   * they leave it (wire:model.blur), so neither does this; `wait` is for a
   * search box, where the person does wait for the list to narrow.
   */
  async fill(target, value, { required = false, note = '', wait = false } = {}) {
    const l = this.loc(target);
    await l.waitFor({ state: 'visible', timeout: STEP_TIMEOUT });
    this.tick();
    this.fields++;
    if (required) this.required++;
    const at = this.mark();
    await l.fill(String(value));
    // wire:model.blur and .change listeners fire when the person moves on.
    await l.dispatchEvent('change');
    await l.blur().catch(() => {});
    if (wait) await this.settle(700);
    this.log(at, `type ${note || value}`);
  }

  async select(target, value, { required = false, note = '' } = {}) {
    const l = this.loc(target);
    await l.waitFor({ state: 'visible', timeout: STEP_TIMEOUT });
    this.tick();
    this.clicks++;
    this.fields++;
    if (required) this.required++;
    const at = this.mark();
    await l.selectOption(value);
    await this.settle();
    this.log(at, `choose ${note || JSON.stringify(value)}`);
  }

  /** A radio, a check box, or the label that stands for one. */
  async choose(target, { required = false, note = '' } = {}) {
    const l = this.loc(target);
    await l.waitFor({ state: 'visible', timeout: STEP_TIMEOUT });
    this.tick();
    this.clicks++;
    this.fields++;
    if (required) this.required++;
    const at = this.mark();
    await l.click();
    await this.settle();
    this.log(at, `set ${note}`.trim());
  }

  /** The person reloads the page (F5): one press, and the page again. */
  async reload(note = 'reload') {
    this.tick();
    this.clicks++;
    const at = this.mark();
    await this.page.reload({ waitUntil: 'networkidle' });
    this.screens.push(this.lastPath + ' (reloaded)');
    await this.settle();
    this.log(at, note);
  }

  /** Enter in a box, when that is what submits or picks. */
  async enter(target, note = '') {
    const l = this.loc(target);
    this.tick();
    this.clicks++;
    const at = this.mark();
    await l.press('Enter');
    await this.settle();
    this.log(at, `enter ${note}`.trim());
  }

  /** The confirmation the job is done: stops the clock. */
  async done(target, note = '') {
    await this.loc(target).waitFor({ state: 'visible', timeout: STEP_TIMEOUT });
    this.t1 = performance.now();
    this.steps.push(`${((this.t1 - this.t0) / 1000).toFixed(1)}s done ${note}`.trim());
  }

  /** Stop the clock now, when the confirmation is not an element. */
  stop(note = '') {
    this.t1 = performance.now();
    this.steps.push(`${((this.t1 - this.t0) / 1000).toFixed(1)}s done ${note}`.trim());
  }

  result(extra = {}) {
    return {
      id: this.id,
      journey: this.title,
      role: this.role,
      clicks: this.clicks,
      fields: this.fields,
      required: this.required,
      screens: this.screens.length,
      dialogs: this.dialogs,
      requests: this.requests,
      seconds: this.t0 !== null && this.t1 !== null ? +((this.t1 - this.t0) / 1000).toFixed(1) : null,
      path: this.screens,
      steps: this.steps,
      notes: this.notes,
      ...this.extra,
      ...extra,
    };
  }
}

// ── Helpers ─────────────────────────────────────────────────────────────

async function signIn(browser, role) {
  const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
  await context.addInitScript((css) => {
    const add = () => {
      if (document.getElementById('td-journeys-hide')) return;
      const s = document.createElement('style');
      s.id = 'td-journeys-hide';
      s.textContent = css;
      (document.head || document.documentElement).appendChild(s);
    };
    document.addEventListener('DOMContentLoaded', add);
    document.addEventListener('livewire:navigated', add);
  }, HIDE_CSS);
  const page = await context.newPage();
  page.setDefaultTimeout(STEP_TIMEOUT);
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', ACCOUNTS[role]);
  await page.fill('input[name="password"]', PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}), page.click('button[type="submit"]')]);
  const where = new URL(page.url()).pathname;
  if (!/\/admin\/?$/.test(where)) {
    await context.close();
    throw new Error(`${role} did not reach the dashboard after signing in (at ${where}); two-step sign-in?`);
  }
  await page.addStyleTag({ content: HIDE_CSS });
  return { context, page };
}

/** A toast whose text contains `text`. */
const toast = (page, text) => page.locator('.tb-toast', { hasText: text }).first();

/** A sidebar entry; opens its group first when it sits in a closed one. */
async function sidebar(m, label, group = null) {
  const link = m.page.locator('.tb-sidebar a', { hasText: new RegExp(`^\\s*${label}\\s*$`, 'i') }).first();
  if (group && !(await link.isVisible())) {
    await m.click(m.page.locator('.tb-sidebar button', { hasText: new RegExp(`^\\s*${group}\\s*$`, 'i') }).first(), `sidebar ${group}`);
  }
  await m.click(link, `sidebar ${label}`);
}

/** The form group whose label reads `label` (inside `scope`, a dialog say). */
const field = (m, label, scope = m.page) => scope.locator('.tb-form-group', {
  has: m.page.locator('label.tb-label', { hasText: new RegExp(`^\\s*${label}\\s*\\*?\\s*$`) }),
}).first();

/**
 * A <livewire:ui.select-search> picker: type into it, then click the row
 * that matches. Counted as one field (typed) and one click (the row).
 */
async function pick(m, container, query, rowText, opts = {}) {
  const input = container.locator('.tb-picker-input input').first();
  await m.fill(input, query, { ...opts, note: opts.note || `search ${query}` });
  const row = container.locator('.tb-picker-option', { hasText: rowText }).first();
  await m.click(row, `pick ${rowText}`);
  await container.locator('.tb-picker-selected').first().waitFor();
}

const stamp = () => {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${p(d.getDate())}${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
};

// A name a person might have: the run stamp keeps re-runs apart.
const RUN = stamp();
const SURNAMES = ['Kintu', 'Namata', 'Ochieng', 'Akiror', 'Mukasa', 'Nabukenya'];
// Letters, not digits: a name that reads like a name, and that no other run
// has used (the run stamp spelt a=0 ... j=9).
const TAG = RUN.replace(/\d/g, (d) => 'abcdefghij'[d]);
const surname = (i) => `${SURNAMES[i % SURNAMES.length]}${TAG}`;

// ── The journeys ────────────────────────────────────────────────────────

const JOURNEYS = {};

export { JOURNEYS, Meter };

// 1a. Register a new patient, then check them in: the dashboard's
// "Register patient" quick action, the full form, then "New visit".
JOURNEYS['1a'] = {
  title: 'Register a new patient, then open a visit (Register patient page)',
  role: 'reception',
  async run(m, state) {
    const { page } = m;
    const last = surname(0);
    await m.click(page.locator('main a', { hasText: 'Register patient' }), 'Register patient');
    await page.locator('input[wire\\:model="first_name"]').waitFor();
    await m.fill('input[wire\\:model="first_name"]', 'Sarah', { required: true, note: 'first name' });
    await m.fill('input[wire\\:model="last_name"]', last, { required: true, note: 'last name' });
    await m.fill('input[wire\\:model="dob"]', '1990-04-12', { note: 'date of birth' });
    await m.select('select[wire\\:model="sex"]', { label: 'Female' }, { note: 'sex' });
    await m.fill('input[wire\\:model="phone_1"]', '0772' + RUN.slice(-6), { note: 'phone' });
    await m.click(page.locator('form button[type="submit"]', { hasText: 'Register patient' }), 'Register patient');
    await page.locator('a', { hasText: 'New visit' }).first().waitFor();
    const patientUrl = page.url();
    await m.click(page.locator('main a', { hasText: 'New visit' }), 'New visit');
    m.dialogs++;
    await page.locator('.tb-picker-selected, .tb-brief').first().waitFor();
    await m.click(page.locator('button[type="submit"]', { hasText: 'Create visit' }), 'Create visit');
    await m.done(toast(page, 'Visit opened'), 'toast Visit opened');
    state.patient1a = { name: `Sarah ${last}`, url: patientUrl };
  },
};

// 1b. The same job in one dialog: Visits, New visit, "New patient".
JOURNEYS['1b'] = {
  title: 'Register a new patient and open a visit in one dialog (Visits, New visit, New patient)',
  role: 'reception',
  async run(m, state) {
    const { page } = m;
    const last = surname(1);
    const phone = '0701' + RUN.slice(-6);
    await sidebar(m, 'Visits');
    await m.open(page.locator('button', { hasText: 'New visit' }), 'New visit');
    await m.click(page.locator('label.tb-kind', { hasText: 'New patient' }), 'New patient');
    await m.fill('#v-first', 'Moses', { required: true, note: 'first name' });
    await m.fill('#v-last', last, { required: true, note: 'last name' });
    await m.choose(page.locator('label.tb-kind', { hasText: /^\s*Male\s*$/ }), { note: 'sex' });
    await m.fill('#v-dob', '1985-09-03', { note: 'date of birth' });
    await m.fill('#v-phone', phone, { note: 'phone' });
    await m.click(page.locator('button[type="submit"]', { hasText: 'Register & create visit' }), 'Register & create visit');
    const t = toast(page, 'New patient registered and visit opened');
    await m.done(t, 'toast');
    const text = await t.innerText();
    state.patient = { first: 'Moses', last, name: `Moses ${last}`, phone, visitNo: (text.match(/V-\d{8}-\d+/) || [])[0] };
  },
};

// 2. Book an appointment for a registered patient: the dashboard's "Book
// appointment", then Book.
JOURNEYS['2'] = {
  title: 'Book an appointment',
  role: 'reception',
  async run(m, state) {
    const { page } = m;
    const who = state.patient1a?.name || state.patient?.name;
    if (!who) throw new Error('needs the patient from journey 1');
    await m.click(page.locator('main a', { hasText: 'Book appointment' }), 'Book appointment');
    await m.open(page.locator('button', { hasText: /^\s*Book\s*$/ }), 'Book');
    const dialog = page.locator('.tb-modal', { hasText: 'Book appointment' }).first();
    await pick(m, field(m, 'Patient', dialog), who.split(' ')[1], who, { required: true, note: 'patient' });
    await pick(m, field(m, 'Doctor', dialog), 'Nakato', 'Nakato', { required: true, note: 'doctor' });
    await m.select('#appt-source', { index: 1 }, { required: true, note: 'source' });
    // The first day the doctor sits (not today: it may be over), then the
    // first free time on it.
    const day = dialog.locator('.tb-daystrip .tb-day:not(.is-away)').nth(1);
    await m.choose(day, { required: true, note: 'day' });
    const slot = dialog.locator('.tb-slots .tb-slot').first();
    if (await slot.isVisible().catch(() => false)) await m.click(slot, 'time');
    await m.click(dialog.locator('button[type="submit"]', { hasText: 'Book appointment' }), 'Book appointment');
    await m.done(toast(page, 'Appointment booked'), 'toast Appointment booked');
  },
};

/**
 * From the Visits list to the visit's own page: search, open the visit (a
 * dialog), then "Full page". Used where the dashboard does not list the visit.
 */
async function openVisitFromList(m, name) {
  const { page } = m;
  await sidebar(m, 'Visits');
  await m.fill('input[aria-label="Search visits"]', name.split(' ')[1], { note: 'search visits', wait: true });
  const row = page.locator('tr', { hasText: name }).first();
  await m.open(row.locator('td.mono button').first(), 'visit number');
  await m.click(page.locator('.tb-modal a', { hasText: 'Full page' }).first(), 'Full page');
  await page.locator('.tb-gate, .tb-detail-cols').first().waitFor();
}

// 3a. Vitals, by the nurse: "Awaiting vitals" on the dashboard, the visit,
// "Record vitals".
JOURNEYS['3a'] = {
  title: 'Consult, part 1: vitals (nurse)',
  role: 'nurse',
  async run(m, state) {
    const { page } = m;
    const who = state.patient?.name;
    if (!who) throw new Error('needs the patient from journey 1b');
    const onDash = page.locator('main a', { hasText: who }).first();
    if (await onDash.isVisible().catch(() => false)) {
      await m.click(onDash, 'patient under Awaiting vitals');
    } else {
      await openVisitFromList(m, who);
    }
    state.visitUrl = page.url();
    await m.open(page.locator('button', { hasText: 'Record vitals' }), 'Record vitals');
    await m.fill('#v-temperature', '38.4', { note: 'temperature' });
    await m.fill('#v-bp', '128/84', { note: 'blood pressure' });
    await m.fill('#v-pulse', '96', { note: 'pulse' });
    await m.fill('#v-spo2', '97', { note: 'SpO2' });
    await m.fill('#v-weight', '71', { note: 'weight' });
    await m.click(page.locator('button[type="submit"]', { hasText: 'Save vitals' }), 'Save vitals');
    await m.done(toast(page, 'Vitals recorded'), 'toast Vitals recorded');
  },
};

// 3b. The consultation, by the doctor: notes (complaint and diagnosis), a lab
// test, a prescription, all on the visit's page.
JOURNEYS['3b'] = {
  title: 'Consult, part 2: complaint, diagnosis, a lab test, a prescription (doctor)',
  role: 'doctor',
  async run(m, state) {
    const { page } = m;
    const who = state.patient?.name;
    if (!who) throw new Error('needs the patient from journey 1b');
    await openVisitFromList(m, who);
    state.visitUrl = page.url();

    await m.open(page.locator('button', { hasText: 'Write notes' }), 'Write notes');
    await m.fill('#cl-complaints', 'Fever and headache for three days', { note: 'complaints' });
    await m.fill('#cl-diagnosis', 'Suspected malaria', { note: 'diagnosis' });
    await m.click(page.locator('button[type="submit"]', { hasText: 'Save notes' }), 'Save notes');
    await toast(page, 'Clinical notes saved').waitFor();

    await m.open(page.locator('button', { hasText: 'Order tests' }), 'Order tests');
    await m.choose(page.locator('.tb-modal label', { hasText: 'Malaria RDT' }).locator('input'), { required: true, note: 'test Malaria RDT' });
    await m.click(page.locator('button[type="submit"]', { hasText: 'Order & bill' }), 'Order & bill');
    await toast(page, 'Lab tests ordered').waitFor();

    await m.open(page.locator('button', { hasText: 'Write prescription' }), 'Write prescription');
    await m.fill('#rx-drug-0', 'Paracetamol', { required: true, note: 'drug' });
    await m.fill('#rx-dose-0', '1g', { note: 'dosage' });
    const slots = page.locator('.tb-modal fieldset label');
    await m.choose(slots.filter({ hasText: 'Morning' }).locator('input'), { required: true, note: 'slot morning' });
    // A second slot on the same line: one more click, not one more field.
    await m.click(slots.filter({ hasText: 'Evening' }).locator('input'), 'slot evening');
    await m.click(page.locator('button[type="submit"]', { hasText: 'Add prescription' }), 'Add prescription');
    await m.done(toast(page, 'Prescription added'), 'toast Prescription added');
  },
};

// 4. The lab result: the worklist, "Enter result", the value and its flag,
// "Save and report back".
JOURNEYS['4'] = {
  title: 'Enter a lab result and release it',
  role: 'lab',
  async run(m, state) {
    const { page } = m;
    const who = state.patient?.name;
    if (!who) throw new Error('needs the patient from journey 1b');
    await m.click(page.locator('main a', { hasText: 'Lab worklist' }), 'Lab worklist');
    const row = page.locator('tr', { hasText: who }).first();
    await m.open(row.locator('button', { hasText: 'Enter result' }), 'Enter result');
    const dialog = page.locator('.tb-modal', { hasText: 'What came back' }).first();
    await m.fill(dialog.locator('input[aria-label^="Result for"]').first(), 'Positive (P. falciparum)', { required: true, note: 'result value' });
    await m.select(dialog.locator('select[aria-label^="Flag for"]').first(), 'abnormal', { note: 'flag' });
    await m.click(dialog.locator('button', { hasText: 'Save and report back' }), 'Save and report back');
    const ok = toast(page, 'Result reported back');
    const refused = toast(page, 'Cannot move a lab order');
    await Promise.race([ok.waitFor(), refused.waitFor()]);
    if (await refused.isVisible()) {
      // The order is still "Ordered": it has to be walked through Collected
      // and Processing first, from the row's menu.
      m.notes.push(`"Save and report back" refused: ${(await refused.innerText()).trim()}`);
      await m.click(dialog.locator('button', { hasText: /^\s*Close\s*$/ }), 'Close');
      let moved = false;
      for (const step of ['Collected', 'Processing']) {
        const item = page.locator('.tb-menu-pop:visible [role="menuitem"]', { hasText: new RegExp(`^\\s*${step}\\s*$`) }).first();
        await m.click(row.locator('button[aria-label^="Actions for"]').first(), 'row menu');
        if (!(await item.isVisible()) && moved) {
          // After a "Move to", that row's menu does not open again until
          // the page is reloaded.
          m.notes.push('after a "Move to" from the row menu, the same row\'s menu would not open again until the page was reloaded');
          await m.reload('reload the page (the row menu stopped opening)');
          await m.click(row.locator('button[aria-label^="Actions for"]').first(), 'row menu');
        }
        if (!(await item.isVisible())) { await page.keyboard.press('Escape'); continue; } // already past this step
        await m.click(item, `Move to ${step}`);
        moved = true;
      }
      await m.open(row.locator('button', { hasText: 'Enter result' }), 'Enter result');
      await m.click(dialog.locator('button', { hasText: 'Save and report back' }), 'Save and report back');
      await m.done(ok, 'toast Result reported back');
    } else {
      await m.done(ok, 'toast Result reported back');
    }
  },
};

// 5. Dispense what was prescribed: find the visit, "Dispense drugs", find the
// drug in stock, the quantity, "Dispense & bill".
JOURNEYS['5'] = {
  title: 'Dispense a prescription',
  role: 'pharmacy',
  async run(m, state) {
    const { page } = m;
    const who = state.patient?.name;
    if (!who) throw new Error('needs the patient from journey 1b');
    await openVisitFromList(m, who);
    const rx = await page.locator('.tb-card', { hasText: 'Prescriptions' }).last().innerText().catch(() => '');
    if (!/Paracetamol/i.test(rx)) m.notes.push('the prescription was not on the visit page');
    await m.open(page.locator('button', { hasText: 'Dispense drugs' }), 'Dispense drugs');
    const dialog = page.locator('.tb-modal', { hasText: 'Drug (from stock)' }).first();
    await pick(m, field(m, 'Drug \\(from stock\\)', dialog), 'Paracetamol', 'Paracetamol', { required: true, note: 'drug from stock' });
    await m.fill('#disp-qty-0', '6', { required: true, note: 'quantity' });
    await m.click(dialog.locator('button[type="submit"]', { hasText: 'Dispense' }), 'Dispense & bill');
    await m.done(toast(page, 'Dispensed and billed'), 'toast Dispensed and billed');
  },
};

/** The first visible element among several. */
async function firstVisible(locator) {
  const n = await locator.count();
  for (let i = 0; i < n; i++) {
    const l = locator.nth(i);
    if (await l.isVisible().catch(() => false)) return l;
  }
  return null;
}

// 6a. Take a cash payment for the visit: find the visit, raise its invoice,
// take the payment, all in the visit dialog. Done by reception: the
// accounts role has no Visits menu and cannot see a bill nobody has invoiced.
JOURNEYS['6a'] = {
  title: 'Take a payment in cash (raise the invoice, then pay in full)',
  role: 'reception',
  async run(m, state) {
    const { page } = m;
    const who = state.patient?.name;
    if (!who) throw new Error('needs the patient from journey 1b');
    await sidebar(m, 'Visits');
    await m.fill('input[aria-label="Search visits"]', who.split(' ')[1], { note: 'search visits', wait: true });
    const row = page.locator('tr', { hasText: who }).first();
    await m.open(row.locator('td.mono button').first(), 'visit number');
    const dialog = page.locator('.tb-modal', { hasText: 'Payments' }).first();
    // The lab released its result and the pharmacy dispensed, but the visit's
    // own orders for that work still read Pending, and the bill cannot be
    // raised while they are open: each has to be opened and marked done.
    const blocked = dialog.locator('.tb-gate-block', { hasText: /still open/ }).first();
    if (await blocked.isVisible().catch(() => false)) {
      m.notes.push(`gate said: ${(await blocked.innerText()).trim()}`);
      const openRows = dialog.locator('tr.tb-ord-row', { hasText: /Pending|In progress/i });
      for (let guard = 0; guard < 6 && (await openRows.count()) > 0; guard++) {
        await m.open(openRows.first(), 'an open order');
        const detail = page.locator('.tb-modal', { has: page.locator('.tb-ord-foot') }).last();
        const done = detail.locator('button', { hasText: /Mark completed/i }).first();
        if (!(await done.isVisible().catch(() => false))) {
          m.notes.push(`an order could not be marked done: ${(await detail.locator('.tb-gate-block').first().innerText().catch(() => '?')).trim()}`);
          break;
        }
        await m.click(done, 'Mark completed');
        const close = detail.locator('button', { hasText: /^\s*Close\s*$/ }).first();
        if (await close.isVisible().catch(() => false)) await m.click(close, 'Close');
      }
    }
    let generate = await firstVisible(dialog.locator('button', { hasText: 'Generate invoice' }));
    if (!generate) {
      // The bill cannot be invoiced until the visit is moved on to billing.
      const ready = await firstVisible(dialog.locator('button', { hasText: 'Ready for billing' }));
      if (!ready) throw new Error('neither "Generate invoice" nor "Ready for billing" is on offer');
      m.notes.push('"Generate invoice" only appeared after "Ready for billing" was pressed');
      await m.click(ready, 'Ready for billing');
      await dialog.locator('button', { hasText: 'Generate invoice' }).first().waitFor();
      generate = await firstVisible(dialog.locator('button', { hasText: 'Generate invoice' }));
    }
    await m.open(generate, 'Generate invoice');
    await m.click(page.locator('button[type="submit"]', { hasText: 'Raise invoice' }), 'Raise invoice');
    await toast(page, 'raised').waitFor();
    await m.open(dialog.locator('button', { hasText: 'Take payment' }), 'Take payment');
    const pay = page.locator('.tb-modal', { hasText: 'How is it being paid?' }).first();
    await m.choose(pay.locator('label.tb-kind', { hasText: 'Cash' }), { required: true, note: 'method cash' });
    await m.click(pay.locator('button[type="submit"]', { hasText: 'Record payment' }), 'Record payment');
    await m.done(toast(page, 'Payment recorded'), 'toast Payment recorded');
  },
};

// 6b. Take a mobile money payment on an invoice already raised: the
// dashboard's outstanding figure, the invoice, "Record payment".
JOURNEYS['6b'] = {
  title: 'Take a payment by mobile money on an outstanding invoice',
  role: 'accounts',
  async run(m) {
    const { page } = m;
    await m.click(page.locator('main a', { hasText: /Outstanding/i }).first(), 'Outstanding');
    const row = page.locator('tbody tr', { hasText: /Issued|Partially paid/i }).first();
    await m.open(row.locator('td.mono button').first(), 'invoice number');
    await m.click(page.locator('.tb-modal a', { hasText: 'Open the invoice' }).first(), 'Open the invoice');
    await m.open(page.locator('main button', { hasText: 'Record payment' }).first(), 'Record payment');
    await m.select('#pay-method', 'mobile_money', { required: true, note: 'method mobile money' });
    await m.fill('#pay-ref', 'MM' + RUN, { required: true, note: 'transaction id' });
    await m.click(page.locator('button[type="submit"]', { hasText: 'Record payment' }), 'Record payment');
    await m.done(toast(page, 'Payment recorded'), 'toast Payment recorded');
  },
};

// 7a. Admit: the occupancy board, a free bed, "Admit into it", the visit.
JOURNEYS['7a'] = {
  title: 'Admit a patient (occupancy board)',
  role: 'doctor',
  async run(m, state) {
    const { page } = m;
    const who = state.patient1a?.name;
    if (!who) throw new Error('needs the patient from journey 1a');
    await sidebar(m, 'Occupancy board', 'Inpatient');
    const bed = page.locator('button.tb-bed.is-free').first();
    await m.open(bed, 'a free bed');
    await m.open(page.locator('.tb-modal button', { hasText: 'Admit into it' }).first(), 'Admit into it');
    const dialog = page.locator('.tb-modal', { hasText: 'Admit patient' }).last();
    await pick(m, field(m, 'Visit', dialog), who.split(' ')[1], who, { required: true, note: 'visit (by patient name)' });
    await m.click(dialog.locator('button[type="submit"]', { hasText: 'Admit patient' }), 'Admit patient');
    const t = toast(page, ' admitted to ');
    await m.done(t, 'toast admitted');
    state.bed = (await t.innerText()).match(/admitted to (.+?)( ·|\.$)/)?.[1];
  },
};

// 7b. The ward round, by the nurse: the board, the patient's bed, the
// admission, a round of vitals.
JOURNEYS['7b'] = {
  title: 'Record a ward round (vitals round on the admission)',
  role: 'nurse',
  async run(m, state) {
    const { page } = m;
    const who = state.patient1a?.name;
    if (!who) throw new Error('needs the patient from journey 1a');
    await m.click(page.locator('main a', { hasText: 'Occupancy board' }).first(), 'Occupancy board');
    await m.open(page.locator('button.tb-bed', { hasText: who }).first(), 'the patient\'s bed');
    await m.click(page.locator('.tb-modal a', { hasText: 'The admission' }).first(), 'The admission');
    await page.locator('#vr-temp').waitFor();
    await m.fill('#vr-temp', '37.6', { note: 'temperature' });
    await m.fill('#vr-bp', '122/80', { note: 'blood pressure' });
    await m.fill('#vr-pulse', '84', { note: 'pulse' });
    await m.fill('#vr-spo2', '98', { note: 'SpO2' });
    await m.fill('#vr-note', 'Comfortable, eating', { note: 'note' });
    await m.click(page.locator('button[type="submit"]', { hasText: 'Record vitals' }), 'Record vitals');
    await m.done(toast(page, 'Vitals round recorded'), 'toast Vitals round recorded');
  },
};

// 7c. Discharge, by the doctor: the board, the bed, "Discharge".
JOURNEYS['7c'] = {
  title: 'Discharge (occupancy board)',
  role: 'doctor',
  async run(m, state) {
    const { page } = m;
    const who = state.patient1a?.name;
    if (!who) throw new Error('needs the patient from journey 1a');
    await sidebar(m, 'Occupancy board', 'Inpatient');
    await m.open(page.locator('button.tb-bed', { hasText: who }).first(), 'the patient\'s bed');
    await m.open(page.locator('.tb-modal button', { hasText: 'Discharge' }).first(), 'Discharge');
    const dialog = page.locator('.tb-modal', { hasText: 'Close admission' }).last();
    await m.fill(dialog.locator('textarea').first(), 'Improved. Review in one week.', { note: 'discharge notes' });
    await m.click(dialog.locator('button[type="submit"]', { hasText: 'Close admission' }), 'Close admission');
    await m.done(toast(page, 'Admission closed'), 'toast Admission closed');
  },
};

// 8. Receive stock: the stock list, find the item, "Receive", how many and
// what was paid.
JOURNEYS['8'] = {
  title: 'Receive stock (a delivery of one item)',
  role: 'pharmacy',
  async run(m) {
    const { page } = m;
    await m.click(page.locator('main a', { hasText: 'Stock list' }).first(), 'Stock list');
    await m.fill('input[aria-label="Search stock items"]', 'Paracetamol', { note: 'search stock', wait: true });
    const row = page.locator('tbody tr', { hasText: 'Paracetamol' }).first();
    await m.open(row.locator('button', { hasText: 'Receive' }).first(), 'Receive');
    await m.fill('#mv-qty', '500', { required: true, note: 'quantity' });
    await m.fill('#mv-total', '60000', { note: 'total paid' });
    await m.fill('#mv-note', 'Delivery note ' + RUN, { note: 'note (supplier, delivery note)' });
    await page.waitForTimeout(600); // the live cost fields answer before the press
    await m.settle();
    await m.click(page.locator('.tb-modal button[type="submit"]', { hasText: 'Receive' }).first(), 'Receive');
    await m.done(toast(page, 'Stock received'), 'toast Stock received');
  },
};

// 9. Close the cashier's day: Billing, "Cashier's day", "Print the day".
JOURNEYS['9'] = {
  title: "Close the cashier's day (see the day, print it)",
  role: 'accounts',
  async run(m) {
    const { page } = m;
    await sidebar(m, "Cashier's day", 'Billing');
    const total = await page.locator('.tb-stats-grid').first().innerText().catch(() => '');
    m.notes.push(`the day showed: ${total.replace(/\s+/g, ' ').trim()}`);
    const [popup] = await Promise.all([
      page.context().waitForEvent('page'),
      m.click(page.locator('a', { hasText: 'Print the day' }).first(), 'Print the day'),
    ]);
    m.screens.push('/admin/cashier/day/pdf (new tab)');
    // Headless Chrome has no PDF viewer: the PDF arriving in full is the
    // moment a person would see it.
    const res = await popup.waitForResponse((r) => r.url().includes('/cashier/day/pdf'), { timeout: STEP_TIMEOUT });
    await res.finished();
    const type = (await res.allHeaders())['content-type'] || '';
    // (Headless Chrome hands a PDF to its downloader, so the header can be
    // missing here even when the server sent application/pdf.)
    if (res.status() !== 200 || (type && !type.includes('pdf'))) m.notes.push(`the print tab answered ${res.status()} ${type}`);
    m.stop('the PDF arrived');
  },
};

/** 10. Find a patient: Patients, type into the search, open the match. */
function findPatient(id, what, query) {
  JOURNEYS[id] = {
    title: `Find a patient from ${what}`,
    role: 'reception',
    async run(m, state) {
      const { page } = m;
      const p = state.patient;
      if (!p) throw new Error('needs the patient from journey 1b');
      const q = query(p);
      if (!q) throw new Error('nothing to search with yet (run 10a first)');
      await sidebar(m, 'Patients');
      await m.fill(page.locator('main input[type="search"]').first(), q, { note: `search "${q}"`, wait: true });
      const row = page.locator('tbody tr', { hasText: p.name }).first();
      if (!(await row.isVisible().catch(() => false))) {
        const shown = (await page.locator('tbody tr').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim()).join(' | ');
        m.notes.push(`"${q}" did not find ${p.name}; the list showed: ${shown.slice(0, 160)}`);
        m.extra.found = false;
        m.stop('not found');
        return;
      }
      m.extra.found = true;
      await m.open(row.locator('button.tb-rowbtn').first(), 'the patient');
      const peek = page.locator('.tb-modal', { hasText: p.name }).last();
      await m.done(peek, 'the patient opened');
      const no = (await peek.innerText().catch(() => '')).match(/PT-[\dA-Z-]+/);
      if (no && !state.patient.no) state.patient.no = no[0];
    },
  };
}
findPatient('10a', 'a name', (p) => p.name);
findPatient('10b', 'a phone number (as registered, 07...)', (p) => p.phone);
findPatient('10c', 'a patient number', (p) => p.no);
findPatient('10d', 'a phone number in the international form (+256...)', (p) => '+256' + p.phone.slice(1));

// ── Runner ──────────────────────────────────────────────────────────────

async function main() {
  const { chromium } = loadPlaywright();
  const chrome = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
  const browser = await chromium.launch({ ...(fs.existsSync(chrome) ? { executablePath: chrome } : {}), headless: !process.env.HEADED });
  const wanted = process.argv.slice(2);
  const state = wanted.length && fs.existsSync(STATE_FILE) ? JSON.parse(fs.readFileSync(STATE_FILE, 'utf8')) : {};
  const results = [];

  try {
    // In journey order (1a, 1b, 2, 3a ... 10d): a plain object would put the
    // all-digit keys first.
    const order = Object.keys(JOURNEYS).sort((a, b) => parseInt(a, 10) - parseInt(b, 10) || a.localeCompare(b));
    for (const id of order) {
      const j = JOURNEYS[id];
      if (wanted.length && !wanted.includes(id)) continue;
      let session;
      try {
        session = await signIn(browser, j.role);
      } catch (e) {
        results.push({ id, journey: j.title, role: j.role, status: 'skipped', error: e.message });
        console.error(`${id}: skipped: ${e.message}`);
        continue;
      }
      const m = new Meter(session.page, id, j.title, j.role);
      m.begin();
      try {
        await j.run(m, state);
        results.push({ ...m.result(), status: 'done' });
        const r = results.at(-1);
        console.error(`${id}: ${r.clicks} clicks, ${r.fields} fields (${r.required} required), ${r.screens} screens, ${r.dialogs} dialogs, ${r.requests} requests, ${r.seconds}s`);
      } catch (e) {
        const shot = path.join(path.dirname(OUT), `journey-${id}-failed.png`);
        await session.page.screenshot({ path: shot, fullPage: true }).catch(() => {});
        results.push({ ...m.result(), status: 'failed', error: e.message.split('\n')[0], screenshot: shot });
        console.error(`${id}: FAILED: ${e.message.split('\n')[0]} (see ${shot})`);
      } finally {
        await session.context.close();
      }
      fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
    }
  } finally {
    await browser.close();
  }

  const summary = { base: BASE, run: RUN, at: new Date().toISOString(), journeys: results };
  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, JSON.stringify(summary, null, 2) + '\n');
  console.log(JSON.stringify(summary, null, 2));
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((e) => { console.error(e); process.exit(1); });
}
