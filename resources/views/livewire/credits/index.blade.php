<div>
  <h1 class="sr-only">Refunds and credit notes</h1>

  <div class="tb-filter-bar tb-toolbar">
    <input type="search" wire:model.live.debounce.400ms="search" class="tb-input tb-input-slim" placeholder="Number, invoice or patient…" aria-label="Search">
    <select wire:model.live="status" class="tb-select" aria-label="Status"><option value="">All statuses</option>
      @foreach($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>
    <select wire:model.live="kind" class="tb-select" aria-label="Kind"><option value="">Both kinds</option>
      @foreach($kinds as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>
    @if($waiting > 0 && $status !== 'pending')
      <button type="button" class="btn-tb btn-tb-sm tb-ml-auto" wire:click="$set('status', 'pending')">
        <i class="fas fa-hourglass-half" aria-hidden="true"></i> {{ $waiting }} waiting for approval
      </button>
    @endif
  </div>

  <div class="tb-card" wire:loading.class="tb-loading" wire:target="search,status,kind"><div class="tb-table-wrap">
    <table class="tb-table">
      <thead><tr><th class="tb-serial">#</th><th>Document</th><th>Invoice</th><th>Patient</th><th class="tb-text-right">Amount</th><th>Status</th><th>Asked</th><th></th></tr></thead>
      <tbody>
        @forelse($credits as $c)
          <tr wire:key="credit-{{ $c->id }}">
            <x-ui.serial :rows="$credits" :loop="$loop" />
            <td>
              <button type="button" class="tb-rowbtn" wire:click="peek({{ $c->id }})" title="See this {{ strtolower($c->kind->label()) }}">
                {{ $c->kind->label() }} <span class="mono">{{ $c->label() }}</span>
              </button>
              <div class="muted tb-xs">{{ \Illuminate\Support\Str::limit($c->reason, 60) }}</div>
            </td>
            <td class="mono">{{ $c->invoice?->invoice_no ?? '—' }}</td>
            <td>{{ $c->patient?->full_name ?? '—' }}</td>
            <td class="tb-text-right mono">{{ \App\Support\HospitalSettings::money($c->amount) }}</td>
            <td><x-ui.badge :tone="$c->status->badge()">{{ $c->status->label() }}</x-ui.badge></td>
            <td class="muted">{{ $c->requested_at->format('d M Y') }} · {{ $c->requestedBy?->name ?? '—' }}</td>
            <td class="tb-text-right tb-nowrap">
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-icon" wire:click="peek({{ $c->id }})"
                      aria-label="See {{ $c->kind->label() }} {{ $c->label() }}"><i class="fas fa-eye" aria-hidden="true"></i></button>
              <x-ui.actions-menu label="Actions for {{ $c->kind->label() }} {{ $c->label() }}">
                <button type="button" role="menuitem" wire:click="peek({{ $c->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Quick view</button>
                @if($c->invoice)
                  <a role="menuitem" wire:navigate href="{{ route('admin.invoices.show', $c->invoice) }}"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> Open the invoice</a>
                @endif
                @if($c->number)
                  <a role="menuitem" href="{{ route('admin.credits.pdf', $c) }}" target="_blank" rel="noopener"><i class="fas fa-file-pdf" aria-hidden="true"></i> Printable</a>
                @endif
                @if($c->isPending())
                  @can('approve', $c)
                    <button type="button" role="menuitem" wire:click="approve({{ $c->id }})"
                            wire:confirm="Approve this {{ strtolower($c->kind->label()) }}? It goes on the invoice and into the books."><i class="fas fa-check" aria-hidden="true"></i> Approve</button>
                    <button type="button" role="menuitem" wire:click="openReject({{ $c->id }})"><i class="fas fa-xmark" aria-hidden="true"></i> Reject</button>
                  @endcan
                @endif
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="8">
            <x-ui.empty icon="fa-file-circle-minus" noun="credit notes or refunds"
                        :filtered="$search !== '' || $status !== '' || $kind !== ''"
                        wire:click="$set('search', '')" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $credits->links(data: $this->paginationData()) }}

  {{-- One credit note or refund, over the list. --}}
  <x-ui.modal show="showPeek" size="md" autosaves
              :title="$this->peeked ? $this->peeked->kind->label().' '.$this->peeked->label() : 'Credit note or refund'">
    @if($this->peeked)
      @php($p = $this->peeked)
      <div class="tb-modal-body">
        <x-ui.peek-head :heading="$p->patient?->full_name ?? '—'" :sub="'Invoice '.($p->invoice?->invoice_no ?? '—')">
          <x-ui.badge :tone="$p->status->badge()">{{ $p->status->label() }}</x-ui.badge>
        </x-ui.peek-head>
        <dl class="tb-peek-facts">
          <dt>Amount</dt><dd class="mono">{{ \App\Support\HospitalSettings::money($p->amount) }}</dd>
          @if(bccomp((string) $p->tax_part, '0', 2) > 0)<dt>Of which tax</dt><dd class="mono">{{ \App\Support\HospitalSettings::money($p->tax_part) }}</dd>@endif
          <dt>Reason</dt><dd>{{ $p->reason }}</dd>
          @if($p->method)<dt>Paid back as</dt><dd>{{ $p->method->label() }}@if($p->card) · {{ $p->card->masked() }}@endif</dd>@endif
          @if($p->reference)<dt>Reference</dt><dd class="mono">{{ $p->reference }}</dd>@endif
          <dt>Asked for</dt><dd>{{ $p->requested_at->format('j M Y · H:i') }} · {{ $p->requestedBy?->name ?? '—' }}</dd>
          @if($p->decided_at)<dt>{{ $p->status->label() }}</dt><dd>{{ $p->decided_at->format('j M Y · H:i') }} · {{ $p->decidedBy?->name ?? '—' }}</dd>@endif
          @if($p->decision_note)<dt>Why rejected</dt><dd>{{ $p->decision_note }}</dd>@endif
        </dl>
      </div>
      <x-ui.peek-foot :href="$p->invoice ? route('admin.invoices.show', $p->invoice) : null" label="Open the invoice" icon="fa-file-invoice-dollar">
        @if($p->number)
          <a class="btn-tb btn-tb-ghost" href="{{ route('admin.credits.pdf', $p) }}" target="_blank" rel="noopener">
            <i class="fas fa-file-pdf" aria-hidden="true"></i> Printable
          </a>
        @endif
        @if($p->isPending())
          @can('approve', $p)
            <button type="button" class="btn-tb btn-tb-ghost" wire:click="openReject({{ $p->id }})"><i class="fas fa-xmark" aria-hidden="true"></i> Reject</button>
            <button type="button" class="btn-tb" wire:click="approve({{ $p->id }})" wire:loading.attr="disabled" wire:target="approve"
                    wire:confirm="Approve this {{ strtolower($p->kind->label()) }}? It goes on the invoice and into the books."><i class="fas fa-check" aria-hidden="true"></i> Approve</button>
          @endcan
        @endif
      </x-ui.peek-foot>
    @endif
  </x-ui.modal>

  <x-ui.modal size="sm" show="showReject" title="Reject">
    @if($showReject)
      <form wire:submit="reject" class="credit-form">
        <div class="tb-modal-body">
          <x-ui.field label="Why" for="reject-note" name="rejectNote" required>
            <input id="reject-note" type="text" wire:model="rejectNote" class="tb-input" maxlength="500" required>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="reject">
            <span wire:loading.remove wire:target="reject"><i class="fas fa-xmark" aria-hidden="true"></i> Reject</span>
            <span wire:loading wire:target="reject"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
