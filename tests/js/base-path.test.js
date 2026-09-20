import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { appBase, appPath, setAppBase } from '../../resources/js/offline/base.js';
import { Transport } from '../../resources/js/offline/transport.js';

/**
 * Where the application lives.
 *
 * This is the bug that made offline mode silently not work at all: every path
 * was hardcoded from the origin root — `/field`, `/api/v1`, `/field-sw.js` —
 * and this installation is served from `/true-doctor/`. Nothing matched, the
 * worker never registered, and the only symptom was the browser's own
 * "this site can't be reached".
 *
 * Silent is the operative word. Nothing threw, no test failed, and the feature
 * looked finished. These tests exist so it cannot happen again.
 */

beforeEach(() => setAppBase(null));
afterEach(() => setAppBase(null));

describe('working out where the app is', () => {
    it('reads the base the server rendered', () => {
        setAppBase('/true-doctor');

        expect(appBase()).toBe('/true-doctor');
        expect(appPath('/field')).toBe('/true-doctor/field');
        expect(appPath('/api/v1')).toBe('/true-doctor/api/v1');
    });

    it('handles an app served at the origin root', () => {
        setAppBase('/');

        expect(appBase()).toBe('');
        expect(appPath('/field')).toBe('/field');
    });

    it('takes the path out of a full url, because config/app.url is one', () => {
        setAppBase('http://localhost:8888/true-doctor/');

        expect(appBase()).toBe('/true-doctor');
        expect(appPath('/api/v1/sync/push')).toBe('/true-doctor/api/v1/sync/push');
    });

    it('strips a trailing slash so paths never double up', () => {
        setAppBase('/true-doctor/');

        expect(appPath('/field')).toBe('/true-doctor/field');
        expect(appPath('/field')).not.toContain('//');
    });
});

describe('the transport goes to the right place', () => {
    it('builds its base from where the app is, not from the origin root', async () => {
        setAppBase('/true-doctor');

        const seen = [];
        const transport = new Transport({
            fetch: async (url) => {
                seen.push(url);

                return { ok: true, status: 200, json: async () => ({ data: {} }) };
            },
        });

        await transport.status();

        // The bug: this used to be http://localhost/api/v1/sync/status, which
        // is a 404 on this installation and reads to the client as "the server
        // is unreachable" — indistinguishable from being genuinely offline.
        expect(seen[0]).toContain('/true-doctor/api/v1/sync/status');
        expect(seen[0]).not.toContain('localhost/api/v1');
    });

    it('still honours an explicit base, for a client pointed somewhere else', async () => {
        const seen = [];
        const transport = new Transport({
            baseUrl: 'https://other.example/api/v1',
            fetch: async (url) => {
                seen.push(url);

                return { ok: true, status: 200, json: async () => ({ data: {} }) };
            },
        });

        await transport.status();

        expect(seen[0]).toBe('https://other.example/api/v1/sync/status');
    });
});

/**
 * The worker derives its own base from where its script was served, which is
 * a fact it can always read — `self.location.pathname`. Asserted here against
 * the source, because there is no service-worker runtime in Vitest and the
 * rule is exactly the kind a future edit breaks without noticing.
 */
describe('the worker knows where it is', () => {
    it('derives its base from its own location rather than assuming the root', async () => {
        const { readFile } = await import('node:fs/promises');
        const source = await readFile(new URL('../../public/field-sw.js', import.meta.url), 'utf8');

        expect(source).toContain("self.location.pathname.replace(/\\/field-sw\\.js$/, '')");

        // And every route it matches is compared AFTER the base is stripped.
        expect(source).toContain('url.pathname.startsWith(BASE)');
        expect(source).toContain("route.startsWith('/api/')");
        expect(source).toContain("route.startsWith('/field')");
        expect(source).toContain("route.startsWith('/admin')");
    });

    it('answers a failed admin navigation with a door rather than nothing', async () => {
        const { readFile } = await import('node:fs/promises');
        const source = await readFile(new URL('../../public/field-sw.js', import.meta.url), 'utf8');

        // The panel cannot work offline — that is architecture, not a fault —
        // but somebody who types its URL should be told, not left with the
        // browser's error page.
        expect(source).toContain('adminOrDoor');
        expect(source).toContain('Continue in Field Mode');

        // And it is GENERATED, never cached: rule 6 stands. Nothing ever puts
        // an admin response into a cache.
        expect(source).not.toMatch(/cache\.put\([^)]*admin/i);
    });
});
