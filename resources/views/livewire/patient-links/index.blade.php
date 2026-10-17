<div>
  <h1 class="sr-only">Patient links</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Patient or number…" aria-label="Search patient links">
    </div>
    <select wire:model.live="state" class="tb-select" aria-label="Filter by state">
      <option value="">All links</option>
      @foreach($states as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,state,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap">
    <table class="tb-table">
      <thead><tr><th class="tb-serial">#</th><th>Document</th><th>Patient</th><th>Sent by</th><th>Works until</th><th class="tb-text-right">Opened</th><th>State</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
        @forelse($links as $link)
          @php($state = $link->state())
          <tr wire:key="pl-{{ $link->id }}">
            <x-ui.serial :rows="$links" :loop="$loop" />
            <td>
              <button type="button" class="tb-rowbtn" wire:click="peek({{ $link->id }})" title="Who opened this link">{{ $link->title }}</button>
              @if($link->reference)<span class="muted mono tb-small">{{ $link->reference }}</span>@endif
            </td>
            <td class="tb-fw-500">
              @if($link->patient)
                <x-ui.link :href="route('admin.patients.show', $link->patient)">{{ $link->patient->full_name }}</x-ui.link>
              @else — @endif
            </td>
            <td class="muted">{{ $link->creator?->name ?? '—' }}<br><span class="tb-small">{{ $link->created_at?->format('d M Y H:i') }}</span></td>
            <td class="muted">{{ $link->expires_at->format('d M Y H:i') }}</td>
            <td class="tb-text-right mono">{{ $link->opens_count }}</td>
            <td><x-ui.badge :tone="\App\Models\PatientLink::stateTone($state)">{{ \App\Models\PatientLink::stateLabel($state) }}</x-ui.badge></td>
            <td class="tb-text-right tb-nowrap">
              <x-ui.actions-menu label="Actions for this link">
                <button type="button" role="menuitem" wire:click="peek({{ $link->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Who opened it</button>
                @if($state === \App\Models\PatientLink::ACTIVE || $state === \App\Models\PatientLink::LOCKED)
                  @can('revoke', $link)
                    <button type="button" role="menuitem" class="danger" wire:click="revoke({{ $link->id }})"
                            wire:confirm="Withdraw this link? It stops working at once."><i class="fas fa-link-slash" aria-hidden="true"></i> Withdraw</button>
                  @endcan
                @endif
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="8">
            <x-ui.empty icon="fa-link" noun="patient links"
                        :filtered="$search !== '' || $state !== ''"
                        wire:click="$set('search', '')">
              @if($search === '' && $state === '')
                <p class="muted tb-small">Send one from a lab or radiology result, a discharge, a visit's prescription or a dental quote.</p>
              @endif
            </x-ui.empty>
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $links->links(data: $this->paginationData()) }}

  <x-ui.modal show="showPeek" size="md" autosaves :title="$this->peeked?->title ?? 'Patient link'">
    @if($this->peeked)
      @php($p = $this->peeked)
      @php($pState = $p->state())
      <div class="tb-modal-body">
        <x-ui.peek-head :heading="$p->patient?->full_name ?? 'Patient'" :sub="trim(($p->reference ?? '').' · sent '.$p->created_at?->format('d M Y H:i').' by '.($p->creator?->name ?? 'the system'), ' ·')">
          <x-ui.badge :tone="\App\Models\PatientLink::stateTone($pState)">{{ \App\Models\PatientLink::stateLabel($pState) }}</x-ui.badge>
        </x-ui.peek-head>

        <x-ui.peek-figs :figures="[
          ['label' => 'Times unlocked', 'value' => (string) $p->opens_count],
          ['label' => 'Wrong codes', 'value' => (string) $p->failed_attempts, 'bad' => $p->failed_attempts > 0],
          ['label' => 'Works until', 'value' => $p->expires_at->format('d M Y')],
        ]" />

        @if($p->message)
          <p class="muted tb-small">Text message: {{ $p->message->status->label() }}@if($p->message->skip_reason) ({{ str_replace('_', ' ', $p->message->skip_reason) }})@endif</p>
        @endif
        @if($p->revoked_at)
          <p class="muted tb-small">Withdrawn {{ $p->revoked_at->format('d M Y H:i') }} by {{ $p->revoker?->name ?? 'staff' }}.</p>
        @endif

        <x-ui.peek-trail title="Opens" :rows="$this->peekedOpens" empty="Nobody has opened this link yet.">
          @foreach($this->peekedOpens as $open)
            <li wire:key="plo-{{ $open->id }}">
              <span class="tb-fw-500">{{ \App\Models\PatientLinkOpen::eventLabel($open->event) }}</span>
              <span class="muted tb-small">{{ $open->created_at?->format('d M Y H:i') }} · {{ $open->ip ?? 'unknown address' }} · {{ \Illuminate\Support\Str::limit((string) $open->user_agent, 60) }}</span>
            </li>
          @endforeach
        </x-ui.peek-trail>
      </div>
      <x-ui.peek-foot :href="$p->patient ? route('admin.patients.show', $p->patient) : null" label="Patient record">
        @if($pState === \App\Models\PatientLink::ACTIVE || $pState === \App\Models\PatientLink::LOCKED)
          @can('revoke', $p)
            <button type="button" class="btn-tb btn-tb-danger" wire:click="revoke({{ $p->id }})"
                    wire:confirm="Withdraw this link? It stops working at once.">Withdraw</button>
          @endcan
        @endif
      </x-ui.peek-foot>
    @endif
  </x-ui.modal>
</div>
