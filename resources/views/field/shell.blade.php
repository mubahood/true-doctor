<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Field Mode · True-Doctor</title>

  {{-- No CSRF token and no session-dependent markup: this page has to render
       from the service-worker cache with no server involved at all, and a
       stale CSRF token would be worse than none (plan §14). --}}
  {{-- Where this app is served from. It is NOT the origin root — this
       installation lives under /true-doctor — and the client needs to know
       before it can reach the API or register a worker. --}}
  <meta name="td-base" content="{{ rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?? '', '/') }}">
  <meta name="td-hospital" content="{{ $hospitalId }}">
  <meta name="td-user" content="{{ $userId }}">
  <meta name="td-user-name" content="{{ $userName }}">

  @vite(['resources/css/field.css', 'resources/js/field.js'])
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body x-data x-init="$store.offline.boot({{ $hospitalId }}, {{ $userId }})">

  {{-- ── The status bar ───────────────────────────────────────────────
       Always present. "Is my work saved?" must never be a question somebody
       has to go looking for the answer to. --}}
  <div class="fm-bar" x-data="fieldStatus" role="status" aria-live="polite">
    <template x-if="!$store.offline.ready">
      <span class="fm-why">Opening your offline copy…</span>
    </template>

    <template x-if="$store.offline.ready">
      <div class="fm-bar" style="position:static;border:0;padding:0;width:100%;">
        <span class="fm-dot" :class="badgeTone"></span>
        <span class="fm-state" x-text="s.connection.label"></span>
        <span class="fm-why" x-show="s.connection.explanation" x-text="s.connection.explanation"></span>

        <span class="fm-gap"></span>

        <span class="fm-pending" :class="s.pending === 0 && 'is-clear'">
          <i class="fas" :class="s.pending === 0 ? 'fa-check' : 'fa-clock'" aria-hidden="true"></i>
          <span x-text="s.pending === 0 ? 'All sent' : s.pending + ' waiting'"></span>
        </span>

        <span class="fm-attention" x-show="s.failed + s.rejected + s.conflicts > 0">
          <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
          <span x-text="(s.failed + s.rejected + s.conflicts) + ' need you'"></span>
        </span>

        <button type="button" class="btn-tb btn-tb-sm" @click="$store.offline.sync()"
                :disabled="s.connection.state === 'syncing'">
          <i class="fas fa-rotate" aria-hidden="true"></i> Sync now
        </button>

        <a class="btn-tb btn-tb-sm btn-tb-ghost" href="{{ route('admin.dashboard') }}">Back to the panel</a>
      </div>
    </template>
  </div>

  <div class="fm-shell">
    {{-- Field Mode could not start at all. Everything else on this page
         assumes a local database; when there is not one, the only honest
         thing to show is what is wrong and what to do about it. --}}
    <template x-if="$store.offline.blocked">
      <div class="fm-bad">
        <strong>Field Mode cannot start on this browser.</strong>
        <span x-text="$store.offline.blocked"></span>
        <div class="muted tb-small tb-mt-2">
          Nothing has been lost. This is about this browser's settings, not your work — anything
          already sent is on the server, and the main panel still works while you have a connection.
        </div>
        <a class="btn-tb btn-tb-sm" href="{{ route('admin.dashboard') }}">Back to the panel</a>
      </div>
    </template>

    {{-- An update is ready. It waits: taking over while somebody is halfway
         through a form loses the form, and unsent work is never at risk
         either way — the worker does not touch the local database. --}}
    <div class="fm-warn" x-show="$store.offline.updateReady" x-cloak>
      <strong>A new version is ready.</strong>
      Your unsent work is kept either way.
      <button type="button" class="btn-tb btn-tb-sm" @click="$store.offline.updateReady()">
        Reload now
      </button>
    </div>

    {{-- A device blocked while it was away wiped itself on the way in. Say so
         once, plainly, rather than showing an empty screen. --}}
    <div class="fm-bad" x-show="$store.offline.revokedMessage" x-cloak>
      <strong>This device was blocked.</strong>
      <span x-text="$store.offline.revokedMessage"></span>
      Its local copy has been cleared.
    </div>

    {{-- The session behind this browser has ended. Nothing is lost and nothing
         is deleted — but the queue will not move until somebody signs in, and
         a nurse must not be left guessing why. --}}
    <template x-if="$store.offline.ready && $store.offline.status?.authExpired">
      <div class="fm-warn">
        <strong>You have been signed out.</strong>
        Your work is still on this device and nothing has been lost. Sign in again and it will be sent.
        <a class="btn-tb btn-tb-sm" href="{{ route('admin.login') }}">Sign in again</a>
      </div>
    </template>

    <template x-if="$store.offline.ready && $store.offline.status?.clock?.state === 'blocked'">
      <div class="fm-bad">
        <strong>This device's clock is wrong.</strong>
        <span x-text="$store.offline.status.clock.message"></span>
      </div>
    </template>

    <template x-if="$store.offline.ready && $store.offline.status?.storage?.critical">
      <div class="fm-warn">
        <strong>This device is almost out of room.</strong>
        Sync now to free space. Nothing unsent will be deleted — but new records may be refused.
      </div>
    </template>

    {{-- ── Not set up yet ───────────────────────────────────────────── --}}
    <template x-if="$store.offline.ready && !$store.offline.blocked && !$store.offline.registered">
      <div class="fm-enable" x-data="{ label: '' }" x-init="label = $store.offline.suggestedName()">
        <h2>Set this device up for offline work</h2>
        <p>
          Once it is set up, this browser keeps a copy of the patients and charts you need, so you can
          carry on when the connection drops. Your work is saved here and sent automatically when the
          server can be reached.
        </p>
        <ul>
          <li>Only the last 30 days of patients, today's visits and current inpatients are kept.</li>
          <li>No invoices, payments or card balances are ever held on a device.</li>
          <li>An administrator can block this device at any time, and its copy is cleared when they do.</li>
          <li>This is a copy of patient information. Treat the machine the way you treat a paper ward file.</li>
        </ul>

        <x-ui.field label="Name this machine (optional)" for="fm-label" name="label"
                    hint="Only so an administrator can tell your machines apart later. Leave it as it is and carry on.">
          <input id="fm-label" type="text" class="tb-input" x-model="label" maxlength="120"
                 placeholder="Maternity desk laptop">
        </x-ui.field>

        <button type="button" class="btn-tb btn-tb-primary"
                @click="$store.offline.enable(label.trim())">
          <i class="fas fa-download" aria-hidden="true"></i> Set up offline work
        </button>
      </div>
    </template>

    {{-- ── Ready ────────────────────────────────────────────────────── --}}
    <template x-if="$store.offline.ready && !$store.offline.blocked && $store.offline.registered">
      <div>
        {{-- The panel's own page header, not a bespoke one. `tb-page-header`
             carries the h1 type scale and the spacing every other screen in
             this system uses; a second heading style is a second design. --}}
        <div class="tb-page-header">
          <div>
            <h1>Field Mode</h1>
            <div class="muted tb-small">
              Signed in as {{ $userName }} ·
              <span x-text="$store.offline.status?.lastSync
                ? 'last synced ' + new Date($store.offline.status.lastSync).toLocaleString()
                : 'not synced yet'"></span>
            </div>
          </div>
        </div>

        @include('field.partials.workflows')
      </div>
    </template>
  </div>
</body>
</html>
