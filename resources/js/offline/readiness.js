import { appPath } from './base.js';
import { storageAvailable } from './db.js';
import { clock } from './clock.js';

/**
 * "Am I ready to walk away from the network?"
 *
 * Everything else in this folder is machinery. This is the one screen's worth
 * of it that a clinician actually has to understand, and it exists because of
 * how the last two offline failures were found: not by a test, but by somebody
 * switching the server off and discovering that something they had assumed was
 * working had never worked at all.
 *
 * Two jobs:
 *
 *   `check()`    — the honest state of this browser, one answer per question,
 *                  each with what to do about it.
 *   `prepare()`  — do all of it, in order, reporting as it goes.
 *
 * Every check answers `ok`, `warn`, `bad` or `unknown`, and **never throws**.
 * A readiness screen that can itself fail is worse than no readiness screen:
 * somebody would read a blank panel as "fine".
 */

export const OK = 'ok';
export const WARN = 'warn';
export const BAD = 'bad';
export const UNKNOWN = 'unknown';

/** Said once, so every degraded check says the same thing. */
const CANNOT_ASK = 'Could not be checked — the app could not open its copy on this device.';

/** How long a worker may take to answer before we stop waiting on it. */
const WORKER_TIMEOUT_MS = 20_000;

export class Readiness {
    /**
     * @param {{client: import('./index.js').OfflineClient,
     *          serviceWorker?: ServiceWorkerContainer,
     *          storage?: StorageManager}} options
     */
    /**
     * `client` may be **null**, and that is the case this screen exists for.
     *
     * A diagnostic that needs a working system in order to run is no use on
     * the day the system does not work — and that was the state it shipped
     * in: when `OfflineClient.boot()` threw, the page rendered a verdict of
     * "unclear", an empty table of checks, and three buttons that silently
     * did nothing, because every handler returned early on a null client.
     *
     * So every check below degrades. The ones that need no client — can this
     * browser store anything, is the worker registered, is the app downloaded
     * — still answer, and those are precisely the ones that explain why the
     * client could not start.
     *
     * @param {{client: ?object, bootError: ?Error, serviceWorker?: ServiceWorkerContainer,
     *          storage?: StorageManager}} options
     */
    constructor({ client = null, bootError = null, serviceWorker = undefined, storage = undefined } = {}) {
        this.client = client ?? null;
        this.bootError = bootError ?? null;
        this.sw = serviceWorker === undefined ? globalThis.navigator?.serviceWorker : serviceWorker;
        this.storage = storage === undefined ? globalThis.navigator?.storage : storage;
    }

    // ── Reading the state ────────────────────────────────────────────────

    /**
     * Every question, answered.
     *
     * Ordered the way somebody would ask them: can this browser do it at all,
     * is the app here, is the device allowed, are the records here, is there
     * room, is anything waiting. The overall verdict is the worst answer in
     * the list, because readiness is not an average.
     */
    async check() {
        const checks = [
            await this.canStoreAnything(),
            await this.canWorkOffline(),
            await this.appDownloaded(),
            await this.deviceRegistered(),
            await this.recordsDownloaded(),
            await this.roomToWork(),
            await this.clockIsRight(),
            await this.nothingWaiting(),
        ];

        return { verdict: worst(checks), checks, at: new Date().toISOString() };
    }

    /**
     * Will this browser let the app keep anything at all?
     *
     * First, because nothing below it means anything if the answer is no —
     * and because the failure is invisible otherwise. A browser with storage
     * blocked for the site does not expose `indexedDB`, Dexie throws
     * `MissingAPIError`, and Field Mode used to sit on "Opening your offline
     * copy…" for ever with the reason only in the console.
     *
     * It is also the one problem on this page a clinician can fix in ten
     * seconds, once somebody tells them what it is.
     */
    async canStoreAnything() {
        const check = { key: 'storage-allowed', label: 'This browser will let the app store your work' };

        // The client refused to start. Its own message names the cause, and
        // it is a better answer than anything inferred here.
        if (this.bootError !== null) {
            return {
                ...check,
                state: BAD,
                detail: String(this.bootError?.message ?? this.bootError),
                fix: 'recheck',
            };
        }

        if (!storageAvailable()) {
            return {
                ...check,
                state: BAD,
                detail: 'Storage is switched off for this site — usually browser shields, blocked '
                    + 'cookies, or a private window. Allow storage for this site and reload.',
                fix: 'recheck',
            };
        }

        // Exposed is not the same as usable: a private window can offer the
        // API and refuse to open a database.
        if (this.client?.db?.isOpen?.() === false) {
            return {
                ...check,
                state: BAD,
                detail: 'This browser offers storage but would not open it. A private window will do this.',
                fix: 'recheck',
            };
        }

        return { ...check, state: OK, detail: 'Allowed.', fix: null };
    }

    /** Does this browser support the machinery at all? */
    async canWorkOffline() {
        const check = {
            key: 'worker',
            label: 'This browser can open the app with no connection',
        };

        if (!this.sw) {
            return {
                ...check,
                state: BAD,
                detail: 'This browser does not support offline working, or the page is not served over HTTPS.',
                fix: null,
            };
        }

        try {
            const registration = await this.sw.getRegistration(appPath('/'));

            if (!registration) {
                return {
                    ...check,
                    state: BAD,
                    detail: 'Offline working is not switched on in this browser yet.',
                    fix: 'prepare',
                };
            }

            if (registration.waiting) {
                return {
                    ...check,
                    state: WARN,
                    detail: 'A newer version is ready and waiting. Reload to take it before you go offline.',
                    fix: 'update',
                };
            }

            if (!registration.active) {
                return { ...check, state: WARN, detail: 'Still starting up. Check again in a moment.', fix: 'recheck' };
            }

            return { ...check, state: OK, detail: 'Ready.', fix: null };
        } catch (error) {
            return { ...check, state: UNKNOWN, detail: String(error?.message ?? error), fix: 'recheck' };
        }
    }

    /**
     * Is the app itself downloaded — the page AND the code it runs?
     *
     * The distinction matters: caching the page without its code is exactly
     * how "Continue in Field Mode" came to land on a browser error page. So
     * this reports the asset count rather than a yes/no, because "9 of 10"
     * is a fact somebody can act on and "not ready" is not.
     */
    async appDownloaded() {
        const check = { key: 'app', label: 'The app is downloaded to this device' };
        const report = await this.workerReport();

        if (report === null) {
            return { ...check, state: UNKNOWN, detail: 'The offline worker did not answer.', fix: 'prepare' };
        }

        const { wanted, held } = report.assets;

        if (report.usable) {
            return {
                ...check,
                state: OK,
                detail: `The page and all ${wanted} of its files are here.`,
                fix: null,
            };
        }

        if (!report.shell) {
            // Why it is not here matters more than the fact. "Signed out" is
            // the one a person can act on, and it is the common case: the
            // download is a background job, and a session that lapses before
            // it runs sends it to the login page instead of Field Mode.
            // Without this, that device and a merely-offline one read the
            // same, and the Prepare button fails again for a reason nobody
            // has been told.
            const because = {
                'signed-out': 'The server asked us to sign in again instead of sending the page. '
                    + 'Sign in, then download.',
                unreachable: 'The server could not be reached to download it.',
            }[report.reason];

            return {
                ...check,
                state: BAD,
                detail: because ?? 'The page itself has not been downloaded yet.',
                fix: report.reason === 'signed-out' ? 'signin' : 'prepare',
            };
        }

        return {
            ...check,
            state: BAD,
            detail: `The page is here but ${wanted - held} of its ${wanted} files are missing, so it would not open.`,
            fix: 'prepare',
        };
    }

    /** Has this machine been named and trusted by the hospital? */
    async deviceRegistered() {
        const check = { key: 'device', label: 'This device is registered with the hospital' };

        if (this.client === null) {
            return { ...check, state: UNKNOWN, detail: CANNOT_ASK, fix: 'recheck' };
        }

        if (!this.client?.isRegistered?.()) {
            return {
                ...check,
                state: WARN,
                detail: 'Not set up yet. One button does it — nothing to fill in first.',
                fix: 'register',
            };
        }

        return { ...check, state: OK, detail: 'Registered, and an administrator can block it at any time.', fix: null };
    }

    /**
     * Are the records actually here, and how old are they?
     *
     * A copy is not "downloaded" once and for ever — it is as good as its last
     * sync. Anything over a day old is called out, because a ward list from
     * last week is worse than knowing you have not got one.
     */
    async recordsDownloaded() {
        const check = { key: 'records', label: 'Your records are downloaded' };

        if (this.client === null) {
            return { ...check, state: UNKNOWN, detail: CANNOT_ASK, fix: 'recheck', counts: {} };
        }

        try {
            const counts = await this.counts();
            const total = Object.values(counts).reduce((sum, n) => sum + n, 0);
            const lastSync = (await this.client.db.sync_state.get('last_sync'))?.value ?? null;

            if (total === 0) {
                return { ...check, state: WARN, detail: 'Nothing downloaded yet — press Prepare this device.', fix: 'download', counts };
            }

            if (lastSync === null) {
                return { ...check, state: WARN, detail: `${total} records, but never synced.`, fix: 'download', counts };
            }

            const ageHours = (Date.now() - new Date(lastSync).getTime()) / 3_600_000;
            const age = describeAge(ageHours);

            if (ageHours > 24) {
                return {
                    ...check,
                    state: WARN,
                    detail: `${total} records, last updated ${age}. Download again before you go.`,
                    fix: 'download',
                    counts,
                };
            }

            return { ...check, state: OK, detail: `${total} records, last updated ${age}.`, fix: null, counts };
        } catch (error) {
            return { ...check, state: UNKNOWN, detail: String(error?.message ?? error), fix: 'recheck' };
        }
    }

    /** The quota, straight from the browser, for when there is no client. */
    async rawStorage() {
        if (!this.storage?.estimate) {
            return { supported: false };
        }

        try {
            const { usage = 0, quota = 0 } = await this.storage.estimate();
            const used = quota > 0 ? usage / quota : 0;

            return { supported: true, usage, quota, warn: used > 0.8, critical: used > 0.92 };
        } catch {
            return { supported: false };
        }
    }

    /** How many of each thing this device is holding. */
    async counts() {
        const stores = ['patients', 'visits', 'admissions', 'vitals', 'nursing_notes', 'med_administrations', 'lab_items'];
        const counts = {};

        for (const store of stores) {
            try {
                counts[store] = await this.client.db[store].count();
            } catch {
                counts[store] = 0;
            }
        }

        return counts;
    }

    async roomToWork() {
        const check = { key: 'storage', label: 'There is room to keep working' };

        // Asked of the browser directly when there is no client, because
        // "how full is this device" is a question that has an answer either
        // way and is worth having when nothing else does.
        const storage = this.client === null ? await this.rawStorage() : await this.client.storage();

        if (!storage.supported) {
            return { ...check, state: UNKNOWN, detail: 'This browser will not say how much room is left.', fix: null };
        }

        const used = `${formatBytes(storage.usage)} of ${formatBytes(storage.quota)} used`;

        if (storage.critical) {
            return {
                ...check,
                state: BAD,
                detail: `${used}. New records may be refused. Sync and free space before you go.`,
                fix: 'download',
            };
        }

        if (storage.warn) {
            return { ...check, state: WARN, detail: `${used}. Getting full.`, fix: null };
        }

        return { ...check, state: OK, detail: used, fix: null };
    }

    /**
     * Is this machine's clock right?
     *
     * It decides what time a vitals round says it was taken at, and a chart
     * with times somebody does not believe is a chart nobody uses.
     */
    async clockIsRight() {
        const check = { key: 'clock', label: "This device's clock agrees with the server" };

        // Never measured? Measure it. The answer is one cheap request away
        // and the app has everything it needs, so warning a clinician about
        // it — about something they could not act on if they wanted to — was
        // just noise on a page that is supposed to reduce it.
        if (clock.health().state === 'warn' && this.client !== null) {
            try {
                await this.client.transport.status();
            } catch {
                // Offline. It sorts itself out on the first contact, and the
                // message below says so rather than asking for anything.
            }
        }

        const health = clock.health();

        if (health.state === 'blocked') {
            return { ...check, state: BAD, detail: health.message, fix: null };
        }

        if (health.state === 'warn') {
            // Still unmeasured means there was no connection to measure
            // against. Nothing is wrong and there is nothing to do, so it
            // does not ask: it says what will happen by itself.
            const never = health.skewMs === 0 && health.jumpMs === 0;

            return {
                ...check,
                state: WARN,
                detail: never
                    ? 'Will be checked automatically the next time this device reaches the server.'
                    : health.message,
                fix: null,
            };
        }

        return { ...check, state: OK, detail: 'Within a few seconds of the server.', fix: null };
    }

    /**
     * Is anything still waiting to go up?
     *
     * Asked on the way OUT as much as the way in: going offline with work
     * already stuck is how a queue quietly grows for a week.
     */
    async nothingWaiting() {
        const check = { key: 'outbox', label: 'Everything you have recorded has been sent' };

        if (this.client === null) {
            return { ...check, state: UNKNOWN, detail: CANNOT_ASK, fix: 'recheck' };
        }

        try {
            const status = await this.client.status();
            const attention = status.failed + status.rejected + status.conflicts;

            if (attention > 0) {
                return {
                    ...check,
                    state: WARN,
                    detail: `${attention} ${plural(attention, 'record needs', 'records need')} a person to look at ${attention === 1 ? 'it' : 'them'}.`,
                    fix: 'openField',
                };
            }

            if (status.pending > 0) {
                return {
                    ...check,
                    state: WARN,
                    detail: `${status.pending} waiting to be sent.`,
                    fix: 'sync',
                };
            }

            return { ...check, state: OK, detail: 'Nothing waiting.', fix: null };
        } catch (error) {
            return { ...check, state: UNKNOWN, detail: String(error?.message ?? error), fix: 'recheck' };
        }
    }

    // ── Doing something about it ─────────────────────────────────────────

    /**
     * Get this device ready, in the order the steps depend on each other.
     *
     * Reports every step as it starts and finishes, so a slow download looks
     * like progress rather than a hung page. A step that fails does not stop
     * the ones that do not depend on it — half a preparation is worth more
     * than none, as long as the screen says which half.
     *
     * @param {{label?: string, onStep?: (step: object) => void}} options
     */
    async prepare({ label = null, onStep = () => {} } = {}) {
        const steps = [];

        const run = async (key, title, work) => {
            const step = { key, title, state: 'running', detail: null };
            steps.push(step);
            onStep({ ...step });

            try {
                const detail = await work();
                Object.assign(step, { state: 'done', detail: detail ?? null });
            } catch (error) {
                Object.assign(step, { state: 'failed', detail: String(error?.message ?? error) });
            }

            onStep({ ...step });

            return step.state === 'done';
        };

        if (this.client === null) {
            // The app still gets downloaded — that is the step most likely to
            // fix things, and it needs no local database. Saying so beats a
            // button that appears to work and does nothing.
            await run('client', 'Opening this device\'s copy', async () => {
                throw new Error(
                    String(this.bootError?.message ?? 'This browser would not let the app open its copy.'),
                );
            });
        }

        if (this.client !== null && !this.client.isRegistered()) {
            await run('register', 'Setting this device up', async () => {
                // No name required. The client derives one — "Amina Nakato ·
                // Chrome on Mac" — and the field is offered pre-filled for
                // anyone who wants "Maternity desk laptop" instead.
                //
                // It used to refuse without one, which turned "let me work
                // offline" into a form, and a form is how somebody decides
                // the feature is not worth the trouble.
                await this.client.registerDevice(label ?? null);

                return 'Ready to hold a copy.';
            });
        }

        await run('app', 'Downloading the app', async () => {
            const report = await this.primeWorker();

            if (report === null) {
                throw new Error('The offline worker did not answer. Reload the page and try again.');
            }

            if (!report.usable) {
                throw new Error(`Only ${report.assets.held} of ${report.assets.wanted} files could be downloaded.`);
            }

            return `The page and all ${report.assets.wanted} of its files.`;
        });

        // Only if the device is registered — an unregistered one has no
        // business pulling patient records, and the server would refuse it.
        if (this.client !== null && this.client.isRegistered()) {
            await run('records', 'Downloading your records', async () => {
                const summary = await this.client.sync();

                if (summary?.errors?.length) {
                    throw new Error(summary.errors[0]);
                }

                if (summary?.skipped) {
                    throw new Error(summary.reason ?? 'Another tab is already syncing.');
                }

                const counts = await this.counts();
                const total = Object.values(counts).reduce((sum, n) => sum + n, 0);

                return `${total} records on this device.`;
            });
        }

        return { steps, readiness: await this.check() };
    }

    /** Push anything waiting and pull what is new. */
    async sync() {
        if (this.client === null) {
            return { errors: [String(this.bootError?.message ?? 'This device has no local copy to sync.')] };
        }

        return this.client.sync();
    }

    // ── Talking to the worker ────────────────────────────────────────────

    workerReport() {
        return this.askWorker('td:status');
    }

    primeWorker() {
        return this.askWorker('td:prime');
    }

    /**
     * One question, one answer, over a private port.
     *
     * Timed out rather than awaited for ever: a worker that has been killed
     * mid-question would otherwise leave the screen spinning, and a spinner
     * that never resolves reads as "still working" when it means "no idea".
     */
    async askWorker(message) {
        const worker = this.sw?.controller;

        if (!worker) {
            return null;
        }

        return new Promise((resolve) => {
            let settled = false;

            const done = (value) => {
                if (!settled) {
                    settled = true;
                    resolve(value);
                }
            };

            try {
                const channel = new MessageChannel();
                channel.port1.onmessage = (event) => done(event.data ?? null);
                worker.postMessage(message, [channel.port2]);
            } catch {
                done(null);

                return;
            }

            setTimeout(() => done(null), WORKER_TIMEOUT_MS);
        });
    }
}

function worst(checks) {
    for (const state of [BAD, UNKNOWN, WARN]) {
        if (checks.some((c) => c.state === state)) {
            return state;
        }
    }

    return OK;
}

function describeAge(hours) {
    if (hours < 1 / 30) {
        return 'just now';
    }

    if (hours < 1) {
        const minutes = Math.round(hours * 60);

        return `${minutes} ${plural(minutes, 'minute', 'minutes')} ago`;
    }

    if (hours < 48) {
        const whole = Math.round(hours);

        return `${whole} ${plural(whole, 'hour', 'hours')} ago`;
    }

    const days = Math.round(hours / 24);

    return `${days} days ago`;
}

function formatBytes(bytes) {
    if (!bytes) {
        return '0 MB';
    }

    const mb = bytes / 1_048_576;

    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${Math.round(mb)} MB`;
}

function plural(n, one, many) {
    return n === 1 ? one : many;
}
