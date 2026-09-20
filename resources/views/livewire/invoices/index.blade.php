<div>
  <h1 class="sr-only">Invoices</h1>
  
  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap" style="position:relative;">
      <i class="fas fa-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--mt2);font-size:12px;"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Invoice no. or patient…" style="padding-left:32px;min-width:220px;">
    </div>
    <select wire:model.live="status" class="tb-select"><option value="">All statuses</option>
      @foreach($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>
    <div wire:loading.flex wire:target="search,status" class="muted" style="font-size:.8rem;align-items:center;gap:6px;"><i class="fas fa-circle-notch fa-spin"></i> Loading…</div>
  
  </div>

  <div class="tb-card" wire:loading.class="tb-loading" wire:target="search,status"><div class="tb-table-wrap">
    <table class="tb-table">
      <thead><tr><th class="tb-serial">#</th><th>Invoice</th><th>Patient</th><th style="text-align:right;">Total</th><th style="text-align:right;">Balance</th><th>Status</th><th>Issued</th><th></th></tr></thead>
      <tbody>
        @forelse($invoices as $inv)
          <tr wire:key="inv-{{ $inv->id }}">
            <x-ui.serial :rows="$invoices" :loop="$loop" />
            {{-- Opens the invoice over the ledger, like everywhere else. --}}
            <td class="mono"><button type="button" class="tb-rowbtn" wire:click="peek({{ $inv->id }})" title="See this invoice">{{ $inv->invoice_no }}</button></td>
            <td style="font-weight:500;">{{ $inv->patient?->full_name ?? '—' }}</td>
            <td style="text-align:right;" class="mono">{{ \App\Support\HospitalSettings::money($inv->total) }}</td>
            <td style="text-align:right;" class="mono">{{ \App\Support\HospitalSettings::money($inv->balance) }}</td>
            <td><span class="badge-tb {{ $inv->status->badge() }}">{{ $inv->status->label() }}</span></td>
            <td class="muted">{{ $inv->issued_at?->format('d M Y') ?? '—' }}</td>
            <td class="tb-nowrap" style="text-align:right;">
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-icon" wire:click="peek({{ $inv->id }})"
                      aria-label="See invoice {{ $inv->invoice_no }}"><i class="fas fa-eye" aria-hidden="true"></i></button>
              <x-ui.actions-menu label="Actions for invoice {{ $inv->invoice_no }}">
                <button type="button" role="menuitem" wire:click="peek({{ $inv->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Quick view</button>
                <a role="menuitem" wire:navigate href="{{ route('admin.invoices.show', $inv) }}"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> Open the invoice</a>
                <a role="menuitem" href="{{ route('admin.invoices.pdf', $inv) }}" target="_blank" rel="noopener"><i class="fas fa-file-pdf" aria-hidden="true"></i> Printable</a>
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="8">
            <x-ui.empty icon="fa-file-invoice-dollar" noun="invoices"
                        :filtered="$search !== '' || $status !== ''"
                        wire:click="$set('search', '')" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $invoices->links(data: $this->paginationData()) }}

  @include('livewire.partials.invoice-peek')
</div>
