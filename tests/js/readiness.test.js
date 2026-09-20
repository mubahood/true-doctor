import { describe, it, expect, beforeEach, vi } from 'vitest';
import { freshDb, repoFor, healthyClock } from './helpers.js';
import { Readiness, OK, WARN, BAD, UNKNOWN } from '../../resources/js/offline/readiness.js';
import { clock } from '../../resources/js/offline/clock.js';

/**
 * "Am I ready to work without a connection?"
 *
 * The danger on this screen is not failing to answer — it is answering
 * **yes** when the truth is no. Somebody reads "Ready", walks into a ward with
 * no signal, and finds out there that the app was never downloaded. So most of
 * what follows is about the false positives.
 */

function clientFor(db, overrides = {}) {
    return {
        db,
        repo: repoFor(db),
        deviceId: 'device-1',
        isRegistered: () => true,
        storage: async () => ({ supported: true, usage: 10, quota: 100, percent: 10, warn: false, critical: false }),
        status: async () => ({ pending: 0, failed: 0, rejected: 0, conflicts: 0 }),
        sync: async () => ({ pushed: 0, pulled: 0, errors: [] }),
        publish: async () => {},
        ...overrides,
    };
}

/** A worker that answers `td:status` / `td:prime` over the supplied port. */
function fakeServiceWorker(report, { onPrime = null } = {}) {
    return {
        controller: {
            postMessage(message, [port]) {
                const answer = message === 'td:prime' && onPrime ? onPrime() : report;
                // Across the channel, exactly as a real worker replies: the
                // page listens on port1 and the worker is handed port2.
                port.postMessage(answer);
            },
        },
        getRegistration: async () => ({ active: {}, waiting: null }),
    };
}

const GOOD_REPORT = { version: 'v3', shell: true, assets: { wanted: 3, held: 3 }, usable: true };

function readinessFor(db, { client = {}, report = GOOD_REPORT, sw = null } = {}) {
    return new Readiness({
        client: clientFor(db, client),
        serviceWorker: sw ?? fakeServiceWorker(report),
    });
}

beforeEach(() => {
    healthyClock();
});

async function withRecords(db, n = 2) {
    for (let i = 0; i < n; i++) {
        await db.patients.add({ uuid: `p${i}`, first_name: 'A', last_name: 'B', deleted_at: null });
    }

    await db.sync_state.put({ key: 'last_sync', value: new Date().toISOString() });
}

describe('the overall verdict', () => {
    it('is ready only when every single check is', async () => {
        const db = await freshDb();
        await withRecords(db);

        const { verdict, checks } = await readinessFor(db).check();

        expect(checks.every((c) => c.state === OK)).toBe(true);
        expect(verdict).toBe(OK);
    });

    it('is the WORST answer, not the average', async () => {
        // Six greens and one red is not "mostly ready". Somebody reading an
        // averaged verdict walks into a ward on the strength of it.
        const db = await freshDb();
        await withRecords(db);

        const { verdict } = await readinessFor(db, {
            report: { version: 'v3', shell: true, assets: { wanted: 3, held: 1 }, usable: false },
        }).check();

        expect(verdict).toBe(BAD);
    });

    it('never says ready when something could not be checked', async () => {
        const db = await freshDb();
        await withRecords(db);

        const { verdict } = await readinessFor(db, { sw: { controller: null, getRegistration: async () => ({ active: {} }) } }).check();

        expect(verdict).not.toBe(OK);
    });
});

describe('is the app actually downloaded', () => {
    it('refuses to call a shell with missing files ready', async () => {
        // The exact bug this screen was built after: the page was cached, the
        // bundle it names was not, and "Continue in Field Mode" led to the
        // browser's error page.
        const db = await freshDb();
        const { checks } = await readinessFor(db, {
            report: { version: 'v3', shell: true, assets: { wanted: 4, held: 3 }, usable: false },
        }).check();

        const app = checks.find((c) => c.key === 'app');

        expect(app.state).toBe(BAD);
        expect(app.detail).toContain('1 of its 4 files are missing');
        expect(app.fix).toBe('prepare');
    });

    it('says plainly when the page itself was never downloaded', async () => {
        const db = await freshDb();
        const { checks } = await readinessFor(db, {
            report: { version: 'v3', shell: false, assets: { wanted: 0, held: 0 }, usable: false },
        }).check();

        expect(checks.find((c) => c.key === 'app').detail).toContain('has not been downloaded');
    });

    it('does not claim ready when the worker never answers', async () => {
        const db = await freshDb();
        const silent = {
            controller: { postMessage() { /* never replies */ } },
            getRegistration: async () => ({ active: {} }),
        };

        vi.useFakeTimers();
        const check = readinessFor(db, { sw: silent }).appDownloaded();
        await vi.advanceTimersByTimeAsync(21_000);
        const result = await check;
        vi.useRealTimers();

        // A spinner that never resolves reads as "still working" when it
        // means "no idea", so the wait is bounded and the answer is honest.
        expect(result.state).toBe(UNKNOWN);
    });
});

describe('are the records here, and how old', () => {
    it('warns when the copy is over a day old', async () => {
        const db = await freshDb();
        await db.patients.add({ uuid: 'p1', first_name: 'A', last_name: 'B', deleted_at: null });
        await db.sync_state.put({
            key: 'last_sync',
            value: new Date(Date.now() - 30 * 3_600_000).toISOString(),
        });

        const check = await readinessFor(db).recordsDownloaded();

        // A ward list from last week is worse than knowing you have not got
        // one, so age is a warning rather than a footnote.
        expect(check.state).toBe(WARN);
        expect(check.detail).toContain('30 hours ago');
        expect(check.fix).toBe('download');
    });

    it('is not yet ready, and says which button fixes it', async () => {
        // A WARNING rather than an error: nothing is broken, the device has
        // simply not been prepared. Reserving red for real faults is what
        // makes red mean something.
        const db = await freshDb();
        const check = await readinessFor(db).recordsDownloaded();

        expect(check.state).toBe(WARN);
        expect(check.detail).toContain('Prepare this device');
        expect(check.fix).toBe('download');
    });

    it('warns when there are records but no sync has ever finished', async () => {
        const db = await freshDb();
        await db.patients.add({ uuid: 'p1', first_name: 'A', last_name: 'B', deleted_at: null });

        expect((await readinessFor(db).recordsDownloaded()).state).toBe(WARN);
    });

    it('counts each kind separately, so a person can sanity-check it', async () => {
        const db = await freshDb();
        await withRecords(db, 3);
        await db.admissions.add({ uuid: 'a1', patient_uuid: 'p0', status: 'admitted', deleted_at: null });

        const check = await readinessFor(db).recordsDownloaded();

        expect(check.counts.patients).toBe(3);
        expect(check.counts.admissions).toBe(1);
        expect(check.counts.lab_items).toBe(0);
    });
});

describe('is anything still waiting to go up', () => {
    it('warns on the way OUT, not just on the way in', async () => {
        // Going offline with work already stuck is how a queue quietly grows
        // for a week.
        const db = await freshDb();
        await withRecords(db);

        const check = await readinessFor(db, {
            client: { status: async () => ({ pending: 4, failed: 0, rejected: 0, conflicts: 0 }) },
        }).nothingWaiting();

        expect(check.state).toBe(WARN);
        expect(check.detail).toContain('4 waiting');
        expect(check.fix).toBe('sync');
    });

    it('sends somebody to Field Mode when a record needs a decision', async () => {
        const db = await freshDb();

        const check = await readinessFor(db, {
            client: { status: async () => ({ pending: 0, failed: 1, rejected: 0, conflicts: 2 }) },
        }).nothingWaiting();

        expect(check.detail).toContain('3 records need');
        expect(check.fix).toBe('openField');
    });
});

describe('the other checks', () => {
    it('reports an unregistered device as pending, not broken, and promises no form', async () => {
        const db = await freshDb();
        const check = await readinessFor(db, { client: { isRegistered: () => false } }).deviceRegistered();

        expect(check.state).toBe(WARN);
        expect(check.fix).toBe('register');
        expect(check.detail).toContain('nothing to fill in');
    });

    it('calls a badly wrong clock bad, because it decides what time a chart says', async () => {
        const db = await freshDb();
        clock.syncWithServer(new Date(Date.now() - 4 * 3_600_000).toISOString());

        expect((await readinessFor(db).clockIsRight()).state).toBe(BAD);
    });

    it('warns before the device is actually full, not at 95%', async () => {
        const db = await freshDb();
        const check = await readinessFor(db, {
            client: { storage: async () => ({ supported: true, usage: 85, quota: 100, warn: true, critical: false }) },
        }).roomToWork();

        expect(check.state).toBe(WARN);
    });

    it('says a full device may refuse new records rather than silently dropping them', async () => {
        const db = await freshDb();
        const check = await readinessFor(db, {
            client: { storage: async () => ({ supported: true, usage: 99, quota: 100, warn: true, critical: true }) },
        }).roomToWork();

        expect(check.state).toBe(BAD);
        expect(check.detail).toContain('may be refused');
    });
});

describe('preparing the device', () => {
    it('downloads the app and the records, and reports each step', async () => {
        const db = await freshDb();
        const synced = [];

        const readiness = readinessFor(db, {
            client: {
                sync: async () => {
                    synced.push(true);
                    await db.patients.add({ uuid: 'pulled', first_name: 'A', last_name: 'B', deleted_at: null });
                    await db.sync_state.put({ key: 'last_sync', value: new Date().toISOString() });

                    return { pulled: 1, errors: [] };
                },
            },
        });

        const seen = [];
        const { steps, readiness: after } = await readiness.prepare({ onStep: (s) => seen.push(`${s.key}:${s.state}`) });

        expect(steps.map((s) => s.key)).toEqual(['app', 'records']);
        expect(steps.every((s) => s.state === 'done')).toBe(true);
        expect(synced).toHaveLength(1);
        expect(after.verdict).toBe(OK);

        // Reported as they START, so a slow download looks like progress
        // rather than a hung page.
        expect(seen).toContain('app:running');
        expect(seen).toContain('records:running');
    });

    it('sets the device up without being given a name at all', async () => {
        // The point of the change: no form to fill in before somebody can
        // work. It used to refuse without a name, which turned "let me work
        // offline" into a form — and a form is how people decide a feature
        // is not worth the trouble.
        const db = await freshDb();
        let registeredWith = 'NOT CALLED';
        let isRegistered = false;

        const readiness = readinessFor(db, {
            client: {
                isRegistered: () => isRegistered,
                registerDevice: async (label) => {
                    registeredWith = label;
                    isRegistered = true;
                },
            },
        });

        const { steps } = await readiness.prepare({});

        expect(steps[0]).toMatchObject({ key: 'register', state: 'done' });
        // Passed through as null so the CLIENT decides what an unnamed
        // machine is called — one place, rather than every caller guessing.
        expect(registeredWith).toBeNull();
        // And it carries straight on to the records.
        expect(steps.map((s) => s.key)).toContain('records');
    });

    it('still uses a name when one is given', async () => {
        const db = await freshDb();
        let registeredWith = null;
        let isRegistered = false;

        const readiness = readinessFor(db, {
            client: {
                isRegistered: () => isRegistered,
                registerDevice: async (label) => {
                    registeredWith = label;
                    isRegistered = true;
                },
            },
        });

        await readiness.prepare({ label: 'Maternity desk laptop' });

        expect(registeredWith).toBe('Maternity desk laptop');
    });

    it('does not pull records to a device the server has not registered', async () => {
        const db = await freshDb();
        let synced = false;

        const readiness = readinessFor(db, {
            client: {
                isRegistered: () => false,
                registerDevice: async () => { throw new Error('refused'); },
                sync: async () => { synced = true; return { errors: [] }; },
            },
        });

        await readiness.prepare({ label: 'Ward laptop' });

        expect(synced).toBe(false);
    });

    it('keeps going after a step fails, and says which one', async () => {
        // Half a preparation is worth more than none, as long as the screen
        // says which half.
        const db = await freshDb();

        const readiness = readinessFor(db, {
            report: { version: 'v3', shell: false, assets: { wanted: 0, held: 0 }, usable: false },
            client: {
                sync: async () => {
                    await db.sync_state.put({ key: 'last_sync', value: new Date().toISOString() });
                    await db.patients.add({ uuid: 'p1', first_name: 'A', last_name: 'B', deleted_at: null });

                    return { errors: [] };
                },
            },
        });

        const { steps } = await readiness.prepare({});

        expect(steps.find((s) => s.key === 'app').state).toBe('failed');
        expect(steps.find((s) => s.key === 'records').state).toBe('done');
    });

    it('reports a sync that came back with an error rather than calling it done', async () => {
        const db = await freshDb();

        const readiness = readinessFor(db, {
            client: { sync: async () => ({ errors: ['Unauthenticated.'] }) },
        });

        const { steps } = await readiness.prepare({});
        const records = steps.find((s) => s.key === 'records');

        expect(records.state).toBe('failed');
        expect(records.detail).toBe('Unauthenticated.');
    });

    it('asks the worker to prime, and uses what it reports back', async () => {
        const db = await freshDb();
        let primed = false;

        const sw = fakeServiceWorker(
            { version: 'v3', shell: false, assets: { wanted: 0, held: 0 }, usable: false },
            {
                onPrime: () => {
                    primed = true;

                    return GOOD_REPORT;
                },
            },
        );

        const { steps } = await readinessFor(db, { sw }).prepare({});

        expect(primed).toBe(true);
        expect(steps.find((s) => s.key === 'app').state).toBe('done');
    });
});

describe('the screen can never itself fail', () => {
    it('answers every check even when the database throws', async () => {
        const db = await freshDb();
        const broken = clientFor(db, {
            status: async () => { throw new Error('gone'); },
            storage: async () => { throw new Error('gone'); },
        });

        // `storage()` throwing is the client's own contract to catch, so this
        // exercises the checks that call into it.
        broken.storage = async () => ({ supported: false });
        broken.db = { ...db, sync_state: { get: async () => { throw new Error('gone'); } } };

        const result = await new Readiness({ client: broken, serviceWorker: fakeServiceWorker(GOOD_REPORT) }).check();

        expect(result.checks).toHaveLength(8);
        expect(result.checks.every((c) => typeof c.state === 'string')).toBe(true);
        // A blank panel would read as "fine". It must not.
        expect(result.verdict).not.toBe(OK);
    });

    it('answers when the browser has no service worker support at all', async () => {
        const db = await freshDb();
        const result = await new Readiness({ client: clientFor(db), serviceWorker: null }).check();

        expect(result.verdict).toBe(BAD);
        expect(result.checks.find((c) => c.key === 'worker').detail).toContain('does not support');
    });
});

describe('why the app is not downloaded', () => {
    /**
     * A signed-out device and a merely-offline one both end up with no shell.
     * They are not the same problem and they do not have the same answer, and
     * "the page has not been downloaded yet" followed by a button that fails
     * the same way is how somebody concludes the feature is broken.
     */
    it('says so when the server sent us to the sign-in page', async () => {
        const db = await freshDb();
        const check = await readinessFor(db, {
            report: { version: 'v3', shell: false, assets: { wanted: 0, held: 0 }, usable: false, reason: 'signed-out' },
        }).appDownloaded();

        expect(check.state).toBe(BAD);
        expect(check.detail).toContain('sign in again');
        // Not 'prepare' — pressing Download would land on the login page
        // again, which is the loop this exists to break.
        expect(check.fix).toBe('signin');
    });

    it('says so when the server could not be reached', async () => {
        const db = await freshDb();
        const check = await readinessFor(db, {
            report: { version: 'v3', shell: false, assets: { wanted: 0, held: 0 }, usable: false, reason: 'unreachable' },
        }).appDownloaded();

        expect(check.detail).toContain('could not be reached');
        expect(check.fix).toBe('prepare');
    });

    it('falls back to the plain answer when there is no reason to give', async () => {
        const db = await freshDb();
        const check = await readinessFor(db, {
            report: { version: 'v3', shell: false, assets: { wanted: 0, held: 0 }, usable: false, reason: null },
        }).appDownloaded();

        expect(check.detail).toContain('has not been downloaded');
        expect(check.fix).toBe('prepare');
    });
});

describe('a browser that will not store anything', () => {
    /**
     * Brave with shields blocking cookies, a private window, an enterprise
     * policy: `indexedDB` is simply not there. Dexie throws `MissingAPIError`,
     * which used to surface as an unhandled Alpine expression error, leaving
     * Field Mode on "Opening your offline copy…" for ever above a blank page.
     *
     * That is the worst possible answer: it reads as a broken app rather than
     * a setting somebody can change in ten seconds.
     */
    it('is reported as the first check, before anything that depends on it', async () => {
        const db = await freshDb();
        const real = globalThis.indexedDB;
        delete globalThis.indexedDB;

        try {
            const result = await readinessFor(db).check();

            expect(result.checks[0].key).toBe('storage-allowed');
            expect(result.checks[0].state).toBe(BAD);
            expect(result.checks[0].detail).toContain('shields');
            expect(result.verdict).toBe(BAD);
        } finally {
            globalThis.indexedDB = real;
        }
    });

    it('is ok when storage is there', async () => {
        const db = await freshDb();

        expect((await readinessFor(db).canStoreAnything()).state).toBe(OK);
    });

    it('is reported when the database is exposed but would not open', async () => {
        const db = await freshDb();
        const check = await readinessFor(db, {
            client: { db: { ...db, isOpen: () => false } },
        }).canStoreAnything();

        expect(check.state).toBe(BAD);
        expect(check.detail).toContain('private window');
    });
});

describe('when the app could not open its copy at all', () => {
    /**
     * The state the screen shipped in, and the one it exists for: a client
     * that would not boot left `checks` empty, the verdict "unclear", and
     * three buttons that silently did nothing. A diagnostic that needs a
     * working system in order to run is no use on the day the system does
     * not work.
     */
    function broken(bootError = new Error('This browser is not letting the app store anything.')) {
        return new Readiness({
            client: null,
            bootError,
            serviceWorker: fakeServiceWorker(GOOD_REPORT),
        });
    }

    it('still answers every check', async () => {
        const result = await broken().check();

        expect(result.checks).toHaveLength(8);
        expect(result.checks.every((c) => typeof c.state === 'string' && c.detail)).toBe(true);
    });

    it('leads with the reason, in the words the client used', async () => {
        const result = await broken(new Error('Storage is switched off for this site.')).check();

        expect(result.checks[0].key).toBe('storage-allowed');
        expect(result.checks[0].state).toBe(BAD);
        expect(result.checks[0].detail).toBe('Storage is switched off for this site.');
        expect(result.verdict).toBe(BAD);
    });

    it('still answers the checks that need no local copy', async () => {
        // These are the ones that explain the failure, so losing them is
        // losing the point of the page.
        const { checks } = await broken().check();

        expect(checks.find((c) => c.key === 'worker').state).toBe(OK);
        expect(checks.find((c) => c.key === 'app').state).toBe(OK);
    });

    it('says which checks it could not answer rather than omitting them', async () => {
        const { checks } = await broken().check();

        for (const key of ['device', 'records', 'outbox']) {
            const check = checks.find((c) => c.key === key);

            expect(check.state).toBe(UNKNOWN);
            expect(check.detail).toContain('could not open its copy');
        }
    });

    it('does not pretend a sync happened', async () => {
        const result = await broken().sync();

        expect(result.errors).toHaveLength(1);
    });

    it('reports a failed step instead of a button that does nothing', async () => {
        // The user-visible complaint: "the buttons in this page are not
        // responsive, even when clicked."
        const { steps } = await broken().prepare({});

        expect(steps[0]).toMatchObject({ key: 'client', state: 'failed' });
        // And the download still runs, because it is the step most likely to
        // help and it needs no local database.
        expect(steps.find((s) => s.key === 'app').state).toBe('done');
        // But nothing tries to pull records into a copy that does not exist.
        expect(steps.find((s) => s.key === 'records')).toBeUndefined();
    });
});

describe('the clock looks after itself', () => {
    /**
     * A device that had never pushed anything had never checked its clock,
     * so the readiness screen warned: "This device has not checked its clock
     * against the server yet." That is a warning about something the app
     * already knew how to fix and nobody else could — exactly the kind of
     * noise a readiness page exists to remove.
     */
    it('measures itself rather than warning that it has not been measured', async () => {
        const db = await freshDb();
        clock.reset?.();
        clock.offsetMs = 0;
        clock.measuredAt = null;

        let asked = 0;

        const check = await readinessFor(db, {
            client: {
                transport: {
                    status: async () => {
                        asked++;
                        clock.syncWithServer(new Date().toISOString(), 10);

                        return {};
                    },
                },
            },
        }).clockIsRight();

        expect(asked).toBe(1);
        expect(check.state).toBe(OK);
    });

    it('asks for nothing when there is no connection to measure against', async () => {
        const db = await freshDb();
        clock.measuredAt = null;

        const check = await readinessFor(db, {
            client: { transport: { status: async () => { throw new Error('offline'); } } },
        }).clockIsRight();

        expect(check.state).toBe(WARN);
        expect(check.fix).toBeNull();
        expect(check.detail).toContain('automatically');
    });

    it('still reports a clock that is genuinely wrong', async () => {
        const db = await freshDb();
        clock.syncWithServer(new Date(Date.now() - 4 * 3_600_000).toISOString(), 0);

        const check = await readinessFor(db, {
            client: { transport: { status: async () => ({}) } },
        }).clockIsRight();

        expect(check.state).toBe(BAD);
    });
});
