<div>
  <x-ui.page-header title="Offline readiness"
    :crumbs="['Dashboard' => route('admin.dashboard'), 'Offline readiness' => null]">
    <x-slot:explain>
      Everything on this page is about <strong>the machine you are using right now</strong>. Check it
      before you go somewhere without a connection: it will tell you what is downloaded, how old it
      is, and whether anything you have recorded is still waiting to be sent.
    </x-slot:explain>
  </x-ui.page-header>

  {{-- Everything below is measured in the browser. The server cannot know
       what this device has downloaded, so it does not guess. --}}
  <div x-data="offlineReadiness" x-init="boot()">

    {{-- ── The app could not open its copy ─────────────────────────────
         At the TOP, because it is the reason every row below it reads
         "could not be checked". Buried at the bottom, as it first was, the
         page looked simply broken — an empty table and buttons that did
         nothing. --}}
    <template x-if="bootError">
      <div class="tb-card tb-mb-4" style="border-color:var(--bad);">
        <div class="tb-card-body">
          <div class="tb-card-title" style="color:var(--bad);">
            <i class="fas fa-circle-xmark" aria-hidden="true"></i>
            The app cannot store anything on this machine
          </div>
          <p x-text="bootError"></p>
          <p class="muted tb-small">
            Nothing has been lost — this is about this browser's settings, not your work. Everything
            already sent is on the server, and the panel you are reading works either way. The checks
            below that need the local copy cannot be answered until this is fixed.
          </p>
        </div>
      </div>
    </template>

    {{-- ── Anything else that stopped the page working ─────────────── --}}
    <template x-if="message && !bootError">
      <div class="tb-card tb-mb-4"><div class="tb-card-body">
        <span class="muted tb-small" x-text="message"></span>
      </div></div>
    </template>

    {{-- ── The verdict ─────────────────────────────────────────────── --}}
    <div class="tb-card tb-mb-4">
      <div class="tb-card-body">
        <template x-if="!ready">
          <div class="muted"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Checking this device…</div>
        </template>

        <template x-if="ready">
          <div>
            <div class="tb-flex" style="align-items:center;gap:12px;flex-wrap:wrap;">
              <span class="tb-badge" :class="verdictClass" x-text="verdictLabel"></span>
              <strong x-text="verdictHeadline"></strong>
              <span style="flex:1"></span>

              <button type="button" class="btn-tb btn-tb-primary" @click="prepare()" :disabled="busy">
                <span x-show="!busy"><i class="fas fa-cloud-arrow-down" aria-hidden="true"></i>
                  <span x-text="anythingMissing ? 'Prepare this device' : 'Download again'"></span></span>
                <span x-show="busy" x-cloak><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Working…</span>
              </button>

              <button type="button" class="btn-tb" @click="refresh()" :disabled="busy">
                <i class="fas fa-rotate" aria-hidden="true"></i> Check again
              </button>

              <a class="btn-tb btn-tb-ghost" href="{{ route('field') }}">
                <i class="fas fa-bed-pulse" aria-hidden="true"></i> Open Field Mode
              </a>
            </div>

            <div class="muted tb-small tb-mt-2" x-show="checkedAt">
              Checked <span x-text="checkedAt"></span>. Nothing here is stored on the server.
            </div>
          </div>
        </template>
      </div>
    </div>

    {{-- ── Naming the machine ──────────────────────────────────────────
         OPTIONAL, and pre-filled. The label is for an administrator reading
         a list of machines that hold patient records — useful, but not worth
         making somebody fill in a form before they can work. One is derived
         from the browser and the signed-in name; this is only for improving
         on it. --}}
    <template x-if="ready && needsLabel">
      <div class="tb-card tb-mb-4"><div class="tb-card-body">
        <x-ui.field label="Name this machine (optional)" for="rd-label" name="label"
                    hint="Only so an administrator can tell your machines apart later. Leave it as it is and press Prepare — you can rename it any time.">
          <input id="rd-label" type="text" class="tb-input" x-model="label" maxlength="120"
                 placeholder="Maternity desk laptop">
        </x-ui.field>
        <p class="muted tb-small">
          Keeping a copy of patient records here is something an administrator can block at any time,
          and the copy is cleared the next time this machine reaches the server.
        </p>
      </div></div>
    </template>

    {{-- ── What is happening right now ─────────────────────────────── --}}
    <template x-if="steps.length">
      <div class="tb-card tb-mb-4"><div class="tb-card-body">
        <div class="tb-card-title tb-mb-2">Preparing</div>
        <template x-for="step in steps" :key="step.key">
          <div class="tb-flex tb-mb-2" style="gap:10px;align-items:baseline;">
            <i class="fas" aria-hidden="true"
               :class="step.state === 'running' ? 'fa-spinner fa-spin' :
                       step.state === 'done' ? 'fa-check' : 'fa-triangle-exclamation'"
               :style="step.state === 'done' ? 'color:var(--ok)' : step.state === 'failed' ? 'color:var(--bad)' : ''"></i>
            <span x-text="step.title"></span>
            <span class="muted tb-small" x-text="step.detail"></span>
          </div>
        </template>
      </div></div>
    </template>

    {{-- ── The checks ──────────────────────────────────────────────── --}}
    <template x-if="ready">
      <div class="tb-card tb-mb-4">
        <div class="tb-table-wrap">
          <table class="tb-table">
            <thead><tr><th style="width:2rem"></th><th>What</th><th>How it is</th><th style="width:10rem"></th></tr></thead>
            <tbody>
              <template x-for="check in checks" :key="check.key">
                <tr>
                  <td>
                    <i class="fas" aria-hidden="true"
                       :class="{'fa-circle-check': check.state === 'ok',
                                'fa-triangle-exclamation': check.state === 'warn',
                                'fa-circle-xmark': check.state === 'bad',
                                'fa-circle-question': check.state === 'unknown'}"
                       :style="check.state === 'ok' ? 'color:var(--ok)' :
                               check.state === 'bad' ? 'color:var(--bad)' :
                               check.state === 'warn' ? 'color:var(--warn)' : ''"></i>
                    <span class="sr-only" x-text="check.state"></span>
                  </td>
                  <td><strong x-text="check.label"></strong></td>
                  <td class="muted" x-text="check.detail"></td>
                  <td>
                    <template x-if="check.fix">
                      <button type="button" class="btn-tb btn-tb-sm" :disabled="busy"
                              @click="doFix(check.fix)" x-text="fixLabel(check.fix)"></button>
                    </template>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    {{-- ── What is on this device, against what there is ───────────── --}}
    <div class="tb-card tb-mb-4">
      <div class="tb-card-body">
        <div class="tb-card-title">What is downloaded</div>
        <p class="muted tb-small">
          The right-hand column is what the server would send you — scoped to what your role may see,
          the last {{ config('sync.patient_window_days', 30) }} days of patients and the current
          inpatients. It is never everything the hospital holds.
        </p>
      </div>
      <div class="tb-table-wrap">
        <table class="tb-table">
          <thead><tr><th>Records</th><th>On this device</th><th>Available to you</th></tr></thead>
          <tbody>
            @foreach($this->available as $entity => $count)
              <tr>
                <td>{{ \Illuminate\Support\Str::of($entity)->replace('_', ' ')->ucfirst() }}</td>
                <td><span x-text="counts['{{ $entity }}'] ?? '—'"></span></td>
                <td>{{ number_format($count) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="tb-card-body">
        <p class="muted tb-small">
          <strong>No money is ever downloaded.</strong> Invoices, payments and card balances are worked
          out on the server under a lock; a figure calculated on a device could be wrong, and a receipt
          showing a wrong balance is worse than no receipt.
        </p>
      </div>
    </div>

    {{-- ── This person's registered machines ───────────────────────── --}}
    @if($this->devices->isNotEmpty())
      <div class="tb-card tb-mb-4">
        <div class="tb-card-body">
          <div class="tb-card-title">Machines registered to you</div>
          <p class="muted tb-small">
            If you no longer use one of these, ask an administrator to block it — a machine that is
            never coming back keeps whatever it already has.
          </p>
        </div>
        <div class="tb-table-wrap">
          <table class="tb-table">
            <thead><tr><th>Machine</th><th>Last synced</th><th>Last seen</th><th>State</th></tr></thead>
            <tbody>
              @foreach($this->devices as $device)
                <tr>
                  <td>
                    <strong>{{ $device->label }}</strong>
                    <span class="muted tb-small" x-show="deviceId === @js($device->device_uuid)"> · this machine</span>
                    <div class="muted tb-small">{{ $device->platform }}</div>
                  </td>
                  <td class="muted">{{ $device->last_sync_at?->diffForHumans() ?? 'never' }}</td>
                  <td class="muted">{{ $device->last_seen_at?->diffForHumans() ?? 'never' }}</td>
                  <td>
                    @if($device->revoked_at)
                      <x-ui.badge tone="danger">Blocked</x-ui.badge>
                      <div class="muted tb-small">{{ $device->revoked_reason }}</div>
                    @else
                      <x-ui.badge tone="success">Active</x-ui.badge>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    @endif

    {{-- ── Leaving ─────────────────────────────────────────────────── --}}
    <div class="tb-card">
      <div class="tb-card-body">
        <div class="tb-card-title">Remove this device's copy</div>
        <p class="muted tb-small">
          Deletes the patient records held in this browser. It <strong>refuses while anything you have
          recorded is still waiting to be sent</strong> — sync first, and it will tell you if it cannot.
        </p>
        <button type="button" class="btn-tb btn-tb-danger" @click="wipe()" :disabled="busy">
          <i class="fas fa-trash" aria-hidden="true"></i> Remove the copy on this device
        </button>
        <div class="tb-mt-2" x-show="message" x-cloak>
          <span class="muted tb-small" x-text="message"></span>
        </div>
      </div>
    </div>
  </div>
</div>
