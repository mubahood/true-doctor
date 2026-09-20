import Alpine from 'alpinejs';
import { OfflineClient, describeThisMachine, signedInName } from './offline/index.js';
import { registerFieldWorker } from './offline/worker.js';
import { Workflows, ValidationError } from './offline/workflows.js';

/**
 * Field Mode — the client-rendered surface that works with no server.
 *
 * Deliberately separate from `admin.js`. The admin panel is Livewire: every
 * click is a POST, and no service worker can change that. This entry is the
 * other thing — a small client that reads and writes IndexedDB and syncs when
 * it can (plan §2).
 *
 * Alpine is bundled HERE and only here. `admin.js` must never import it: the
 * admin layout gets Alpine from Livewire's own bundle, and a second copy would
 * double-boot every `x-data` on the page. Field Mode has no Livewire, so it
 * needs its own.
 */

let client = null;

document.addEventListener('alpine:init', () => {
    Alpine.store('offline', {
        ready: false,
        status: null,
        registered: false,
        revokedMessage: null,
        /** Set when a new version is waiting. Nothing happens until the user
         *  says so: an update that takes over mid-form loses the form. */
        updateReady: null,

        /**
         * Why Field Mode could not start at all.
         *
         * Distinct from every other state on this screen: those are things a
         * clinician can work around, and this is not. It has to say what is
         * wrong in a sentence, because the alternative — which is what used to
         * happen — is "Opening your offline copy…" for ever above a blank
         * page, and that reads as a broken app rather than a setting.
         */
        blocked: null,

        async boot(hospitalId, userId) {
            // A device that was blocked while it was away wiped itself on the
            // way in. Say so, once, rather than showing an empty screen.
            try {
                this.revokedMessage = sessionStorage.getItem('td-offline-revoked');
                sessionStorage.removeItem('td-offline-revoked');
            } catch {
                // No session storage; the message is a courtesy, not the point.
            }

            client = new OfflineClient({ hospitalId, userId });

            try {
                await client.boot();
            } catch (error) {
                // Nothing below this line can work, so say so and stop rather
                // than throwing out of an Alpine expression — which is how
                // this screen came to hang on its own loading message with a
                // console error nobody but a developer would ever see.
                this.blocked = String(error?.message ?? error);
                this.ready = true;

                return;
            }

            client.on((status) => {
                this.status = status;
                this.registered = status.registered;
            });

            this.status = await client.publish();
            this.registered = this.status.registered;
            this.ready = true;

            if (this.registered) {
                // Not awaited: the screen is usable from the first paint, and
                // the first sync can take a while on a slow link.
                client.syncIfUseful();
            }

            // The worker only makes the app OPEN with no network. Everything
            // else — the local database, the outbox — works without it, so a
            // browser that refuses one is not broken.
            registerFieldWorker({
                onUpdateReady: (apply) => {
                    this.updateReady = apply;
                },
            });
        },

        client: () => client,
        flows: () => (client ? new Workflows(client) : null),

        /** A name for this machine, offered pre-filled so nobody has to think
         *  of one before they can work. */
        suggestedName: () => describeThisMachine(signedInName()),

        async enable(label) {
            await client.registerDevice(label);
            await client.sync();
        },

        sync: () => client?.sync(),
    });

    /**
     * The one place a form reports what happened.
     *
     * Every capture screen behaves identically: it says what it saved, or it
     * says what is missing, and it never leaves somebody wondering whether a
     * vitals round went in. `ValidationError` carries the problems a person
     * can fix; anything else is reported as itself rather than as "something
     * went wrong".
     */
    Alpine.data('fieldForm', (handler) => ({
        busy: false,
        /** Field name → message, exactly as the panel shows them. */
        errors: {},
        /** Anything that belongs to the form as a whole rather than a field. */
        problems: [],
        saved: null,

        /**
         * What the form actually contains, read from the form.
         *
         * NOT from an `x-model`. A browser autofill, a password manager or a
         * paste can all put a value in an input without Alpine ever seeing
         * it — which is precisely what happened: a registration form showing
         * "Muhindo" and "Mubaraka" in the name boxes reported that a first
         * name and a last name were needed, because the model behind it was
         * still empty. The form told the truth and the model did not.
         *
         * `FormData` cannot disagree with what somebody is looking at.
         */
        values() {
            const form = this.$el.matches('form') ? this.$el : this.$el.querySelector('form');

            if (!form) {
                return {};
            }

            const out = {};

            for (const [key, value] of new FormData(form).entries()) {
                out[key] = typeof value === 'string' ? value.trim() : value;
            }

            // An unchecked checkbox is absent from FormData rather than
            // false, which would read as "not answered" instead of "no".
            for (const box of form.querySelectorAll('input[type="checkbox"][name]')) {
                out[box.name] = box.checked;
            }

            return out;
        },

        async submit(...args) {
            this.busy = true;
            this.errors = {};
            this.problems = [];
            this.saved = null;

            try {
                const result = await handler.call(this, this.values(), ...args);

                this.saved = result?.message ?? 'Saved on this device.';
                this.reset?.();

                // Not awaited: the record is already safe locally, and a nurse
                // must never wait for a network to move on to the next bed.
                Alpine.store('offline').client()?.syncIfUseful();
            } catch (error) {
                if (error instanceof ValidationError) {
                    // Under the control it belongs to, the way the panel does
                    // it. A stack of red banners above a form reads as a list
                    // of unrelated complaints and leaves somebody hunting for
                    // which box is wrong.
                    this.errors = error.errors ?? {};
                    this.problems = error.problems ?? [];
                } else {
                    this.problems = [String(error?.message ?? error)];
                }
            } finally {
                this.busy = false;
            }
        },

        /** Clear a field's error as soon as somebody starts fixing it. */
        clear(field) {
            if (this.errors[field]) {
                delete this.errors[field];
            }
        },
    }));

    /** Finding a patient in what this device holds. */
    Alpine.data('fieldPatients', () => ({
        term: '',
        results: [],
        searching: false,
        openUuid: null,

        async init() {
            await this.search();
        },

        open(patientUuid) {
            this.openUuid = this.openUuid === patientUuid ? null : patientUuid;
        },

        /** The patient the correction form is open on. */
        get patient() {
            return this.results.find((p) => p.uuid === this.openUuid) ?? null;
        },

        async search() {
            this.searching = true;
            this.results = await (Alpine.store('offline').client()?.search(this.term) ?? []);
            this.searching = false;
        },
    }));

    /**
     * Decisions waiting on a person.
     *
     * Two answers and only two, because a third would be a merge and a merge
     * is what the server already tried.
     */
    Alpine.data('fieldConflicts', () => ({
        items: [],
        busy: null,

        async init() {
            await this.load();
        },

        async load() {
            const resolver = Alpine.store('offline').client()?.conflicts;

            if (!resolver) {
                this.items = [];

                return;
            }

            const open = await resolver.open();
            this.items = await Promise.all(open.map((c) => resolver.describe(c.id)));
        },

        async decide(id, keepMine) {
            this.busy = id;

            const resolver = Alpine.store('offline').client().conflicts;
            await (keepMine ? resolver.keepMine(id) : resolver.keepServer(id));

            await this.load();
            await Alpine.store('offline').client().publish();

            // Only "keep mine" queues anything; syncing after "keep theirs"
            // costs a request that has nothing to send.
            if (keepMine) {
                Alpine.store('offline').client().syncIfUseful();
            }

            this.busy = null;
        },

        show(value) {
            if (value === null || value === undefined || value === '') {
                return '(empty)';
            }

            return Array.isArray(value) ? value.join(', ') : String(value);
        },
    }));

    /** The bench: tests waiting for a number. */
    Alpine.data('fieldBench', () => ({
        worklist: [],
        openUuid: null,
        term: '',

        async init() {
            await this.load();
        },

        /** Matches the test name or the order it came in on. */
        get filtered() {
            const needle = this.term.trim().toLowerCase();

            if (needle === '') {
                return this.worklist;
            }

            return this.worklist.filter((i) => [i.name, i.order_no, i.unit]
                .filter(Boolean).join(' ').toLowerCase().includes(needle));
        },

        /** The test the report panel is open on. */
        get item() {
            return this.worklist.find((i) => i.uuid === this.openUuid) ?? null;
        },

        async load() {
            this.worklist = await (Alpine.store('offline').flows()?.benchWorklist() ?? []);
        },

        open(uuid) {
            this.openUuid = this.openUuid === uuid ? null : uuid;
        },
    }));

    /** Open visits, and the narrative on each. */
    Alpine.data('fieldNotes', () => ({
        visits: [],
        openUuid: null,
        term: '',

        async init() {
            await this.load();
        },

        /** Matches the patient, the visit number or what they came in saying. */
        get filtered() {
            const needle = this.term.trim().toLowerCase();

            if (needle === '') {
                return this.visits;
            }

            return this.visits.filter((v) => [
                v.patient?.first_name,
                v.patient?.last_name,
                v.patient?.patient_no,
                v.visit_no,
                v.complaints,
            ].filter(Boolean).join(' ').toLowerCase().includes(needle));
        },

        /** The visit the note panel is open on. */
        get visit() {
            return this.visits.find((v) => v.uuid === this.openUuid) ?? null;
        },

        async load() {
            this.visits = await (Alpine.store('offline').flows()?.openVisits() ?? []);
        },

        open(uuid) {
            this.openUuid = this.openUuid === uuid ? null : uuid;
        },
    }));

    /** The ward: who is in a bed, and their chart. */
    Alpine.data('fieldWard', () => ({
        admissions: [],
        openUuid: null,
        chart: null,
        /** A filter over what is already here — not a search of the server. */
        term: '',

        async init() {
            await this.load();
        },

        /**
         * The rows the table shows.
         *
         * Matches on the name, the number and the bed, because a nurse looking
         * for somebody has whichever of those they happen to know.
         */
        get filtered() {
            const needle = this.term.trim().toLowerCase();

            if (needle === '') {
                return this.admissions;
            }

            return this.admissions.filter((a) => [
                a.patient?.first_name,
                a.patient?.last_name,
                a.patient?.patient_no,
                a.bed_name,
            ].filter(Boolean).join(' ').toLowerCase().includes(needle));
        },

        /** The stay the record panel is open on. */
        get stay() {
            return this.admissions.find((a) => a.uuid === this.openUuid) ?? null;
        },

        async load() {
            this.admissions = await (Alpine.store('offline').flows()?.currentInpatients() ?? []);
        },

        async open(admissionUuid) {
            this.openUuid = this.openUuid === admissionUuid ? null : admissionUuid;
            this.chart = this.openUuid
                ? await Alpine.store('offline').flows().chartFor(this.openUuid)
                : null;
        },

        async refresh() {
            await this.load();

            if (this.openUuid) {
                this.chart = await Alpine.store('offline').flows().chartFor(this.openUuid);
            }
        },
    }));

    Alpine.data('fieldStatus', () => ({
        get s() {
            return Alpine.store('offline').status;
        },
        get badgeTone() {
            const state = this.s?.connection?.state;

            if (state === 'offline') return 'is-off';
            if (state === 'degraded' || state === 'sync_error') return 'is-warn';
            if (state === 'syncing') return 'is-busy';

            return 'is-ok';
        },
    }));
});

Alpine.start();
window.Alpine = Alpine;
