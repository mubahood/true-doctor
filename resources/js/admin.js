import { registerFieldWorker } from './offline/worker.js';

/**
 * Offline: the door, not the panel.
 *
 * This panel is Livewire — every screen is rendered by the server, and no
 * service worker can change that. What the worker DOES do from here is answer
 * a failed navigation with a page that says so and offers Field Mode, instead
 * of the browser's "this site can't be reached".
 *
 * Registering from here also primes Field Mode's shell for somebody who only
 * ever opens the admin panel, so the door has somewhere to lead. No Livewire
 * HTML is ever cached (see public/field-sw.js, rule 6).
 */
if (window.isSecureContext || location.hostname === 'localhost') {
    registerFieldWorker({
        onUpdateReady: (apply) => {
            // The panel reloads on navigation constantly, so there is no form
            // to interrupt the way there is in Field Mode: take it quietly on
            // the next idle moment rather than interrupting to ask.
            document.addEventListener('livewire:navigated', apply, { once: true });
        },
    });
}

/**
 * True-Doctor admin shell.
 *
 * Loaded in <head> via @vite so it executes exactly once per browser session,
 * never on wire:navigate (Livewire only re-runs <body> scripts). Alpine itself
 * is bundled with Livewire (@livewireScripts) — we only register data
 * components on `alpine:init` and hook Livewire's navigation lifecycle once.
 */

// ── Body scroll lock (reference-counted; shared by slide-overs/modals) ─────
let locks = 0;
let lockedScrollY = 0;
window.tbScrollLock = function (on) {
    locks = Math.max(0, locks + (on ? 1 : -1));
    const body = document.body;
    if (locks > 0 && !body.classList.contains('tb-scroll-lock')) {
        lockedScrollY = window.scrollY;
        body.style.top = `-${lockedScrollY}px`;
        body.style.position = 'fixed';
        body.style.width = '100%';
        body.classList.add('tb-scroll-lock');
    } else if (locks === 0 && body.classList.contains('tb-scroll-lock')) {
        body.classList.remove('tb-scroll-lock');
        body.style.position = '';
        body.style.top = '';
        body.style.width = '';
        window.scrollTo(0, lockedScrollY);
    }
};

// ── Session flash → toast bridge ──────────────────────────────────────────
// Each server render drops <template data-td-flash> into <main>; the toast
// host is persisted across navigations, so we read the marker on every
// page (initial load + each wire:navigate) and dispatch it as a toast.
function bridgeFlash() {
    document.querySelectorAll('template[data-td-flash]').forEach((tpl) => {
        try {
            const payload = JSON.parse(tpl.dataset.tdFlash || '[]');
            payload.forEach((t) => window.dispatchEvent(new CustomEvent('toast', { detail: t })));
        } catch (e) { /* ignore malformed marker */ }
        tpl.remove();
    });
}

// ── Title mirror (server renders <title>; the topbar mirrors it) ──────────
function pageTitle() {
    return (document.title || '').replace(/\s·\sTrue-Doctor$/, '').trim() || 'Dashboard';
}

// ── Promise-based confirm (used by the dirty-guard; components use wire:confirm)
window.tdConfirm = function (message) {
    return new Promise((resolve) => {
        window.dispatchEvent(new CustomEvent('td:confirm', { detail: { message, resolve } }));
    });
};

// ── Livewire lifecycle: registered exactly once ───────────────────────────
document.addEventListener('livewire:navigated', () => {
    locks = 0;
    document.body.classList.remove('tb-scroll-lock');
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.width = '';
    bridgeFlash();
    window.dispatchEvent(new CustomEvent('td:page', { detail: { title: pageTitle() } }));
});

// Dirty-guard: any element carrying data-td-dirty="1" (set via wire:dirty)
// blocks navigation until the user confirms discarding the changes.
document.addEventListener('livewire:navigate', (e) => {
    if (!document.querySelector('[data-td-dirty="1"]')) return;
    e.preventDefault();
    const url = e.detail?.url?.href ?? e.detail?.url;
    window.tdConfirm('You have unsaved changes. Discard them and leave this page?').then((ok) => {
        if (ok && url) {
            document.querySelectorAll('[data-td-dirty="1"]').forEach((el) => el.removeAttribute('data-td-dirty'));
            window.Livewire?.navigate(url);
        }
    });
});
window.addEventListener('beforeunload', (e) => {
    if (document.querySelector('[data-td-dirty="1"]')) { e.preventDefault(); e.returnValue = ''; }
});

document.addEventListener('DOMContentLoaded', bridgeFlash);

// ── Alpine components for the persisted shell regions ─────────────────────
/** A `<meta name="…">` value, or null. */
function meta(name) {
    const tag = document.querySelector(`meta[name="${name}"]`);
    const value = tag?.content?.trim();

    return value ? value : null;
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * The offline readiness screen.
     *
     * The one page in the Livewire panel that talks to the offline machinery
     * directly, because every question on it is about THIS browser and no
     * server can answer any of them.
     *
     * Note what is not imported: Alpine. The panel gets Alpine from Livewire's
     * own bundle and a second copy double-boots every `x-data` on the page.
     * The offline modules bring none of their own.
     */
    Alpine.data('offlineReadiness', () => ({
        ready: false,
        busy: false,
        checks: [],
        counts: {},
        steps: [],
        verdict: 'unknown',
        checkedAt: null,
        deviceId: null,
        needsLabel: false,
        label: '',
        message: null,
        /** The app could not open its local copy. Shown at the TOP, because it
         *  is the reason every other row below reads "could not be checked". */
        bootError: null,
        _rd: null,

        async boot() {
            try {
                const { OfflineClient, describeThisMachine, signedInName } = await import('./offline/index.js');
                const { Readiness } = await import('./offline/readiness.js');

                const hospitalId = meta('td-hospital');
                const userId = meta('td-user');

                if (hospitalId === null || userId === null) {
                    this.message = 'This page needs a hospital context.';
                    this.ready = true;

                    return;
                }

                let client = null;
                let bootError = null;

                try {
                    client = await new OfflineClient({
                        hospitalId: Number(hospitalId),
                        userId: Number(userId),
                    }).boot();
                } catch (error) {
                    // NOT fatal to this page. A diagnostic that needs a
                    // working system in order to run is no use on the day the
                    // system does not work — and the checks that need no
                    // client are exactly the ones that explain why this
                    // failed. Carry on with a null client.
                    bootError = error;
                }

                // Pre-filled, never demanded. The field is there to improve
                // on this, not to gate on it.
                this.label = describeThisMachine(signedInName());
                this._rd = new Readiness({ client, bootError });
                this.bootError = bootError === null ? null : String(bootError?.message ?? bootError);
                this.deviceId = client?.deviceId ?? null;

                await this.refresh();
            } catch (error) {
                // A readiness screen that can itself fail must SAY so. A blank
                // panel reads as "fine", which is the one thing it must never
                // accidentally mean.
                this.message = `This device could not be checked: ${error?.message ?? error}`;
                this.ready = true;
            }
        },

        async refresh() {
            if (!this._rd) {
                return;
            }

            const result = await this._rd.check();

            this.checks = result.checks;
            this.verdict = result.verdict;
            this.counts = result.checks.find((c) => c.key === 'records')?.counts ?? {};
            this.needsLabel = this._rd.client !== null && !this._rd.client.isRegistered();
            this.deviceId = this._rd.client?.deviceId ?? null;
            this.checkedAt = new Date().toLocaleTimeString();
            this.ready = true;
        },

        async prepare() {
            if (!this._rd || this.busy) {
                return;
            }

            this.busy = true;
            this.steps = [];
            this.message = null;

            try {
                await this._rd.prepare({
                    label: this.label,
                    onStep: (step) => {
                        const at = this.steps.findIndex((s) => s.key === step.key);
                        at === -1 ? this.steps.push(step) : (this.steps[at] = step);
                    },
                });

                await this.refresh();
            } finally {
                this.busy = false;
            }
        },

        async doFix(fix) {
            if (fix === 'recheck') {
                await this.refresh();

                return;
            }

            if (fix === 'update') {
                globalThis.location.reload();

                return;
            }

            if (fix === 'signin') {
                globalThis.location.reload();

                return;
            }

            if (fix === 'openField') {
                globalThis.location.href = `${meta('td-base') ?? ''}/field`;

                return;
            }

            if (fix === 'sync') {
                this.busy = true;

                try {
                    await this._rd.sync();
                    await this.refresh();
                } finally {
                    this.busy = false;
                }

                return;
            }

            // 'prepare', 'register' and 'download' are all the same sequence:
            // it skips the steps that are already done, so there is no reason
            // to offer three buttons that differ only in where they start.
            await this.prepare();
        },

        async wipe() {
            if (!this._rd || this.busy) {
                return;
            }

            this.busy = true;
            this.message = null;

            if (this._rd.client === null) {
                this.message = 'There is no local copy on this device to remove.';
                this.busy = false;

                return;
            }

            try {
                const result = await this._rd.client.signOut();

                if (!result.wiped) {
                    this.message = `Not removed: ${result.outstanding} `
                        + `${result.outstanding === 1 ? 'record is' : 'records are'} still waiting to be sent. `
                        + 'Sync first, or open Field Mode to see what they are.';

                    return;
                }

                this.message = 'The copy on this device has been removed.';
                await this.boot();
            } finally {
                this.busy = false;
            }
        },

        get anythingMissing() {
            return this.checks.some((c) => c.state === 'bad' || c.state === 'unknown');
        },

        get verdictLabel() {
            return { ok: 'Ready', warn: 'Mostly ready', bad: 'Not ready', unknown: 'Unclear' }[this.verdict] ?? 'Unclear';
        },

        get verdictClass() {
            // The design system's own tones — `badge-*`, the same ones
            // `<x-ui.badge>` emits. Inventing `tb-badge-ok` here rendered an
            // unstyled badge, and `DesignSystemCssTest` says so by name.
            return { ok: 'badge-success', warn: 'badge-warn', bad: 'badge-danger' }[this.verdict] ?? 'badge-neutral';
        },

        get verdictHeadline() {
            return {
                ok: 'You can work without a connection from this machine.',
                warn: 'This machine would work offline, but read the warnings first.',
                bad: 'This machine is not ready to work without a connection.',
                unknown: 'Some things about this machine could not be checked.',
            }[this.verdict] ?? '';
        },

        fixLabel(fix) {
            return {
                prepare: 'Download', register: 'Set up', download: 'Download again',
                sync: 'Send now', recheck: 'Check again', update: 'Reload', openField: 'Open',
                signin: 'Sign in',
            }[fix] ?? 'Fix';
        },
    }));

    Alpine.data('tdSidebar', () => ({
        open: false,
        init() {
            const close = () => { this.open = false; };
            document.addEventListener('livewire:navigated', close);
            window.addEventListener('td:sidebar-toggle', () => { this.open = !this.open; });
            // this region is @persist-ed; no cleanup needed for the life of the tab
        },
    }));

    // Collapsible nav groups. The sidebar is persisted, so the active item is
    // re-derived client-side after every navigation (longest matching href).
    Alpine.data('tdNav', (activeGroup) => ({
        open: Alpine.$persist(activeGroup || '').as('td_nav_open'),
        init() {
            if (activeGroup) this.open = activeGroup;
            document.addEventListener('livewire:navigated', () => this.syncActive());
        },
        toggle(key) { this.open = this.open === key ? '' : key; },
        syncActive() {
            const path = location.pathname.replace(/\/+$/, '');
            let best = null; let bestLen = -1;
            this.$root.querySelectorAll('[data-nav-item] a').forEach((a) => {
                const href = new URL(a.getAttribute('href'), location.origin).pathname.replace(/\/+$/, '');
                if ((path === href || path.startsWith(href + '/')) && href.length > bestLen) { best = a; bestLen = href.length; }
            });
            this.$root.querySelectorAll('[data-nav-item]').forEach((li) => {
                const on = best && li.contains(best);
                li.classList.toggle('active', !!on);
                const a = li.querySelector('a');
                on ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current');
            });
            const group = best ? best.closest('[data-nav-item]').dataset.navGroup : '';
            this.$root.querySelectorAll('button[data-nav-group]').forEach((b) => b.classList.toggle('has-active', b.dataset.navGroup === group));
            if (group) this.open = group;
        },
    }));

    /**
     * Row actions menu (x-ui.actions-menu).
     *
     * The popup is position:fixed and placed from the trigger's rect, because
     * every table lives in .tb-table-wrap{overflow-x:auto} — an absolutely
     * positioned child of a row is clipped by it. Fixed means the menu also
     * has to close when the page moves under it, hence the scroll/resize
     * listeners; repositioning instead would fight the sticky footer and any
     * wire:poll re-render underneath.
     */
    Alpine.data('tdMenu', () => ({
        open: false,
        style: '',
        init() {
            this._away = () => this.close(false);
            // Only one menu open at a time, and never a menu floating over a
            // page that has scrolled or navigated away beneath it.
            window.addEventListener('td:menu-open', (e) => { if (e.detail !== this.$el) this.close(false); });
            window.addEventListener('scroll', this._away, true);
            window.addEventListener('resize', this._away);
            document.addEventListener('livewire:navigated', this._away);
        },
        toggle() { this.open ? this.close(true) : this.show(); },
        show() {
            const r = this.$refs.trigger.getBoundingClientRect();
            const w = 232;
            // Flip to the left of the trigger when the right edge would overflow,
            // and upwards when there is more room above than below.
            const left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
            const below = window.innerHeight - r.bottom;
            this.style = below < 240 && r.top > below
                ? `left:${left}px;bottom:${window.innerHeight - r.top + 6}px;width:${w}px;`
                : `left:${left}px;top:${r.bottom + 6}px;width:${w}px;`;
            this.open = true;
            window.dispatchEvent(new CustomEvent('td:menu-open', { detail: this.$el }));
        },
        close(refocus) {
            if (!this.open) return;
            this.open = false;
            if (refocus) this.$refs.trigger.focus();
        },
        items() {
            return Array.from(this.$refs.pop.querySelectorAll('[role="menuitem"]:not([disabled])'));
        },
        focusItem(i) {
            const list = this.items();
            if (!list.length) return;
            (list[i] ?? list[list.length - 1]).focus();
        },
        move(step) {
            const list = this.items();
            const at = list.indexOf(document.activeElement);
            if (!list.length) return;
            list[(at + step + list.length) % list.length].focus();
        },
    }));

    /**
     * The visit dialog's scrolling pane and its rail.
     *
     * Every section is already in the DOM, so jumping is a scroll, never a
     * fetch — that is the whole reason the dialog renders the visit in one
     * pass. An IntersectionObserver keeps the rail showing where you actually
     * are, so the rail is a map rather than a set of buttons that forget.
     */
    Alpine.data('tdVisitPane', (initial) => ({
        at: initial || 'summary',
        init() {
            const pane = this.$refs.pane;
            if (!pane) return;

            // Land on the section the caller asked for, without animating past
            // everything above it.
            this.$nextTick(() => this.jump(this.at, 'auto'));

            // "Where am I" = the topmost section crossing the upper third of
            // the pane; plain scroll maths would miss short sections.
            this._io = new IntersectionObserver((entries) => {
                const seen = entries.filter((e) => e.isIntersecting)
                    .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
                if (seen) this.at = seen.target.dataset.vs;
            }, { root: pane, rootMargin: '0px 0px -66% 0px', threshold: 0 });

            pane.querySelectorAll('[data-vs]').forEach((el) => this._io.observe(el));

            // The last section can be too short to ever reach the trigger line,
            // so the bottom of the scroll always counts as being on it.
            pane.addEventListener('scroll', () => {
                if (pane.scrollTop + pane.clientHeight >= pane.scrollHeight - 4) {
                    const last = pane.querySelector('[data-vs]:last-of-type');
                    if (last) this.at = last.dataset.vs;
                }
            }, { passive: true });
        },
        destroy() { this._io?.disconnect(); },
        jump(key, behavior = 'smooth') {
            const el = this.$refs.pane?.querySelector(`[data-vs="${key}"]`);
            if (!el) return;
            this.at = key;
            this.$refs.pane.scrollTo({ top: el.offsetTop - 8, behavior });
        },
    }));

    Alpine.data('tdTopbar', () => ({
        userMenu: false,
        title: pageTitle(),
        init() {
            window.addEventListener('td:page', (e) => { this.title = e.detail.title; });
        },
        toggleSidebar() { window.dispatchEvent(new CustomEvent('td:sidebar-toggle')); },
    }));

    Alpine.data('tdClock', () => ({
        t: '',
        init() {
            const tick = () => { this.t = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };
            tick();
            const id = setInterval(tick, 15000);
            return () => clearInterval(id);
        },
    }));

    Alpine.data('tdToasts', () => ({
        toasts: [],
        add(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message: detail.message ?? '', type: detail.type ?? 'success' });
            setTimeout(() => this.remove(id), detail.timeout ?? 4200);
        },
        remove(id) { this.toasts = this.toasts.filter((t) => t.id !== id); },
        icon(type) {
            return { success: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info', warning: 'fa-triangle-exclamation' }[type] || 'fa-circle-check';
        },
    }));

    Alpine.data('tdConfirm', () => ({
        open: false, message: '', resolve: null,
        init() {
            window.addEventListener('td:confirm', (e) => {
                this.message = e.detail.message; this.resolve = e.detail.resolve; this.open = true;
            });
        },
        answer(v) { this.open = false; const r = this.resolve; this.resolve = null; r && r(v); },
    }));

    Alpine.data('tdOffline', () => ({
        off: !navigator.onLine,
        init() {
            window.addEventListener('offline', () => { this.off = true; });
            window.addEventListener('online', () => { this.off = false; });
        },
    }));

    // Modal: open state is entangled with the server; focus is trapped while
    // open and restored to the trigger on close.
    /**
     * A drop target for a Livewire file property.
     *
     * The real control is the hidden <input>; this just makes the whole panel
     * a bigger target for it. A dropped file goes straight up — there is no
     * Save button on the dialog it lives in, so there must be none here.
     */
    Alpine.data('tdDrop', ($wire, prop) => ({
        over: false,
        busy: false,
        drop(event) {
            this.over = false;
            const files = Array.from(event.dataTransfer?.files || []);
            if (!files.length) return;

            this.busy = true;
            const done = () => { this.busy = false; };
            $wire.uploadMultiple(prop, files, done, done);
        },
    }));

    Alpine.data('tdModal', (entangled) => ({
        open: entangled,
        trigger: null,
        init() {
            this.$watch('open', (v) => {
                window.tbScrollLock(v);
                const stack = (window.__tdModals ||= []);
                if (v) {
                    // A dialog can open on top of another — a visit's workspace
                    // holds panels whose own writes open one. Escape is bound to
                    // the window, so without a stack it would close every layer
                    // at once instead of the one in front.
                    stack.push(this.$el);
                    this.trigger = document.activeElement;
                    this.$nextTick(() => {
                        const first = this.$el.querySelector('input:not([type=hidden]),select,textarea,button.tb-modal-x');
                        first && first.focus();
                    });
                } else {
                    const i = stack.lastIndexOf(this.$el);
                    if (i > -1) stack.splice(i, 1);
                    if (this.trigger && document.contains(this.trigger)) this.trigger.focus();
                }
            });
        },
        /** Only the dialog in front answers Escape. */
        get isTop() {
            const stack = window.__tdModals || [];
            return stack.length === 0 || stack[stack.length - 1] === this.$el;
        },
        async requestClose() {
            const dirty = this.$el.querySelector('[data-td-dirty="1"]');
            if (dirty && !(await window.tdConfirm('Discard your unsaved changes?'))) return;
            dirty && dirty.removeAttribute('data-td-dirty');
            this.open = false;
        },
    }));
});
