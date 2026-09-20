import { appPath } from './base.js';
import { clock } from './clock.js';

/**
 * The one place that talks to the server.
 *
 * Everything the sync engine knows about the network comes through here, which
 * means "are we online?" has exactly one answer rather than a different one per
 * caller. It also means a test can hand in a fake and drive every failure mode
 * the plan lists (§19.1) without a browser.
 *
 * A request that TIMES OUT or fails at the transport layer is reported
 * differently from one the server answered. The distinction is the whole game:
 * a transport failure means "we do not know whether this was applied" and the
 * operations go back to pending for a retry the server will deduplicate; a
 * server answer means we know, and the results can be applied.
 */

export class HttpError extends Error {
    constructor(status, code, message, body) {
        super(message ?? `HTTP ${status}`);
        this.name = 'HttpError';
        this.status = status;
        this.code = code;
        this.body = body;
    }
}

export class TransportError extends Error {
    constructor(message, cause) {
        super(message);
        this.name = 'TransportError';
        this.cause = cause;
    }
}

export class Transport {
    /**
     * @param {{baseUrl?: string, deviceId?: string|null, token?: string|null,
     *          fetch?: typeof fetch, timeoutMs?: number}} options
     */
    constructor(options = {}) {
        // Not `/api/v1`: this app is served from a subdirectory, and a
        // hardcoded root path resolved to somewhere that does not exist.
        this.baseUrl = options.baseUrl ?? appPath('/api/v1');
        this.deviceId = options.deviceId ?? null;
        this.token = options.token ?? null;
        this._fetch = options.fetch ?? globalThis.fetch?.bind(globalThis);
        // Long enough for a slow rural link, short enough that a dead server
        // does not hold the queue for a minute.
        this.timeoutMs = options.timeoutMs ?? 20_000;
    }

    setToken(token) {
        this.token = token;
    }

    /**
     * The CSRF token, read fresh from the cookie on every request.
     *
     * Field Mode authenticates with the ordinary session cookie, so Laravel
     * requires the matching CSRF token on every write. It is read HERE rather
     * than baked into the page, because the page is served from the service
     * worker cache: a token captured when the shell was cached would be days
     * old, and a stale token is refused exactly like a missing one.
     *
     * `XSRF-TOKEN` is the one cookie Laravel deliberately leaves unencrypted
     * so a browser client can read it. It is not the credential — the session
     * cookie is, and that one stays HttpOnly and out of JavaScript's reach.
     */
    csrfToken() {
        try {
            const match = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(globalThis.document?.cookie ?? '');

            return match ? decodeURIComponent(match[1]) : null;
        } catch {
            return null;
        }
    }

    setDeviceId(deviceId) {
        this.deviceId = deviceId;
    }

    async request(method, path, { body = null, query = null, timeoutMs = null } = {}) {
        if (!this._fetch) {
            throw new TransportError('No fetch implementation available.');
        }

        const url = new URL(this.baseUrl + path, globalThis.location?.origin ?? 'http://localhost');

        for (const [key, value] of Object.entries(query ?? {})) {
            if (value !== null && value !== undefined && value !== '') {
                url.searchParams.set(key, value);
            }
        }

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs ?? this.timeoutMs);
        const startedAt = Date.now();

        let response;

        try {
            const csrf = this.csrfToken();

            response = await this._fetch(url.toString(), {
                method,
                signal: controller.signal,
                // Without this the session cookie is not sent, and every
                // request is 401 — which is precisely what happened before
                // this line existed. Field Mode is authenticated by the same
                // session as the rest of the panel; there is no second
                // credential and nothing to store on the device.
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...(body ? { 'Content-Type': 'application/json' } : {}),
                    ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}),
                    ...(this.deviceId ? { 'X-Device-Id': this.deviceId } : {}),
                    ...(csrf ? { 'X-XSRF-TOKEN': csrf } : {}),
                },
                body: body ? JSON.stringify(body) : undefined,
            });
        } catch (error) {
            // Aborted, DNS failure, connection reset, captive portal — all of
            // them mean the same thing to us: we do not know what happened.
            throw new TransportError(
                error?.name === 'AbortError' ? 'The server did not answer in time.' : 'Could not reach the server.',
                error,
            );
        } finally {
            clearTimeout(timer);
        }

        let payload = null;

        try {
            payload = await response.json();
        } catch {
            // A proxy error page, a truncated body. Not JSON, so not an answer
            // from our server whatever the status says.
            if (response.ok) {
                throw new TransportError('The server sent something we could not read.');
            }
        }

        // Every response the server sends carries the time it sent it, so the
        // clock is set HERE — once, at the single point every reply passes
        // through — rather than by whichever caller happens to remember.
        //
        // It used to be set only from a push response, which meant a device
        // that had never pushed anything had never checked its clock, and the
        // readiness screen warned about it. That is a warning nobody can act
        // on about something the app already knew and could fix itself.
        //
        // The round trip is halved off: the server's `now` is already that
        // old by the time it is read, and on a slow rural link that is the
        // difference between "correct" and "a few seconds out".
        this.noteServerTime(payload, Date.now() - startedAt);

        if (!response.ok) {
            throw new HttpError(
                response.status,
                payload?.code ?? null,
                payload?.message ?? null,
                payload,
            );
        }

        return payload?.data ?? payload;
    }

    /**
     * Set the clock from a reply, wherever it came from.
     *
     * `status`, `register`, `push`, `pull` and `ack` all carry `server_time`,
     * and the cheap status probe runs every fifteen seconds while a device is
     * offline — so in practice the clock is checked constantly and nobody is
     * ever asked to think about it.
     */
    noteServerTime(payload, roundTripMs) {
        const serverTime = payload?.data?.server_time ?? payload?.server_time ?? null;

        if (typeof serverTime === 'string') {
            clock.syncWithServer(serverTime, roundTripMs);
        }
    }

    status() {
        // Short timeout: this doubles as the reachability probe, and a probe
        // that takes twenty seconds to fail is not a probe.
        return this.request('GET', '/sync/status', { timeoutMs: 5_000 });
    }

    register({ deviceUuid, label, platform }) {
        return this.request('POST', '/sync/register', {
            body: { device_uuid: deviceUuid, label, platform },
        });
    }

    push(operations, protocolVersion) {
        return this.request('POST', '/sync/push', {
            body: { operations, protocol_version: protocolVersion },
        });
    }

    pull(cursor) {
        return this.request('GET', '/sync/pull', { query: { cursor } });
    }

    ack(cursor, applied) {
        return this.request('POST', '/sync/ack', { body: { cursor, applied } });
    }

    reference(since) {
        return this.request('GET', '/sync/reference', { query: { since } });
    }
}
