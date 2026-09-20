{{-- One device, over the list.

     What a person asks of a device is what it has been DOING — and the answer
     is a list of operations, with what happened to each. Not what was in them:
     the ledger stores a hash, not a record, so a clinical note is never
     readable from a diagnostics screen (plan §20). --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->label ?? 'Device'">
  @if($this->peeked)
    @php($d = $this->peeked)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$d->label"
                      :sub="($d->user?->name ?? 'No user').' · '.($d->platform ? Str::limit($d->platform, 50) : 'unknown browser')">
        @if($d->isActive())
          <x-ui.badge tone="success">Working</x-ui.badge>
        @else
          <x-ui.badge tone="danger">Blocked</x-ui.badge>
        @endif
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Last seen', 'value' => $d->last_seen_at?->diffForHumans() ?? 'never',
         'sub' => $d->last_seen_at?->format('j M Y · H:i')],
        ['label' => 'Last synced', 'value' => $d->last_sync_at?->diffForHumans() ?? 'never',
         'bad' => $d->last_sync_at === null],
        ['label' => 'Operations sent', 'value' => (string) \App\Models\SyncOperation::where('device_id', $d->id)->count()],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Registered</dt>
        <dd>{{ $d->registered_at?->format('j M Y · H:i') ?? '—' }}</dd>
        <dt>Device id</dt><dd class="mono">{{ $d->device_uuid }}</dd>
        <dt>Protocol</dt><dd>v{{ $d->protocol_version }}</dd>
        @if(! $d->isActive())
          <dt>Blocked</dt>
          <dd class="tb-peek-bad">
            {{ $d->revoked_at?->format('j M Y · H:i') }}@if($d->revokedBy) by {{ $d->revokedBy->name }}@endif
          </dd>
          <dt>Reason given</dt><dd>{{ $d->revoked_reason ?: '—' }}</dd>
        @endif
        <dt>What it holds</dt>
        <dd class="muted">
          The last 30 days of patients, today's visits, current inpatients and their charts.
          Never invoices, payments or card balances.
        </dd>
      </dl>

      <x-ui.peek-trail title="Recent sync rounds" :rows="$this->peekedSessions"
                       empty="This device has never synced.">
        @foreach($this->peekedSessions as $session)
          <li wire:key="sess-{{ $session->id }}">
            <span class="tb-peek-step">
              {{ ucfirst($session->kind) }} · {{ $session->operation_count }}
              {{ Str::plural('operation', $session->operation_count) }}
              @if($session->rejected_count)<span class="tb-peek-bad">{{ $session->rejected_count }} refused</span>@endif
              @if($session->conflict_count)<span class="tb-peek-bad">{{ $session->conflict_count }} in conflict</span>@endif
            </span>
            <span class="tb-peek-meta">
              {{ $session->created_at?->format('j M · H:i') }}
              @if($session->duration_ms) · {{ $session->duration_ms }}ms @endif
              @if($session->duplicate_count) · {{ $session->duplicate_count }} already had @endif
            </span>
          </li>
        @endforeach
      </x-ui.peek-trail>

      <x-ui.peek-trail title="Recent operations" :rows="$this->peekedOperations"
                       empty="This device has sent nothing.">
        @foreach($this->peekedOperations as $op)
          <li wire:key="op-{{ $op->id }}">
            <span class="tb-peek-step">
              <span @class(['tb-mv-qty', 'is-in' => $op->status === 'accepted'])>{{ $op->status }}</span>
              {{ $op->operation }} {{ Str::of($op->entity)->replace('_', ' ') }}
            </span>
            <span class="tb-peek-meta">
              {{ $op->created_at?->format('j M · H:i') }} · <span class="mono">{{ Str::limit($op->entity_uuid, 8, '') }}</span>
            </span>
            @if($op->message)<span class="tb-peek-note">{{ $op->message }}</span>@endif
          </li>
        @endforeach
      </x-ui.peek-trail>

      <p class="tb-out-none">
        What each operation contained is not shown and is not stored — only a fingerprint of it.
        A clinical record is readable by whoever may see the patient, not by whoever may see this page.
      </p>
    </div>

    <x-ui.peek-foot>
      @can('manage', \App\Models\Device::class)
        @if($d->isActive())
          <button type="button" class="btn-tb btn-tb-danger" wire:click="openRevoke({{ $d->id }})">
            <i class="fas fa-ban" aria-hidden="true"></i> Block this device
          </button>
        @else
          <button type="button" class="btn-tb" wire:click="restore({{ $d->id }})"
                  wire:confirm="Let this device work offline again?">
            <i class="fas fa-rotate-left" aria-hidden="true"></i> Allow it again
          </button>
        @endif
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
