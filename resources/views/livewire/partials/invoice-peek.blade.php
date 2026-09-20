{{-- One invoice, over the ledger.

     The row says what it comes to. Everything anybody asks next — what is it
     FOR, what has been paid, how, and by whom — was a page-load away, and a
     cashier working down a list of unpaid invoices asks it of every one. --}}
<x-ui.modal show="showPeek" size="lg" autosaves
            :title="$this->peeked ? 'Invoice '.$this->peeked->invoice_no : 'Invoice'">
  @if($this->peeked)
    @php($inv = $this->peeked)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$inv->patient?->full_name ?? '—'"
                      :sub="$inv->issued_at ? 'Issued '.$inv->issued_at->format('j M Y') : 'Not issued yet'">
        <x-ui.badge :tone="$inv->status->badge()">{{ $inv->status->label() }}</x-ui.badge>
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Total', 'value' => \App\Support\HospitalSettings::money($inv->total)],
        ['label' => 'Paid', 'value' => \App\Support\HospitalSettings::money($inv->amount_paid)],
        ['label' => 'Still to pay',
         'value' => \App\Support\HospitalSettings::money($inv->balance),
         'bad' => bccomp((string) $inv->balance, '0', 2) > 0],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Invoice no.</dt><dd class="mono">{{ $inv->invoice_no }}</dd>
        <dt>Patient no.</dt><dd class="mono">{{ $inv->patient?->patient_no ?? '—' }}</dd>
        <dt>Visit</dt>
        <dd>
          @if($inv->visit)
            <span class="mono">{{ $inv->visit->visit_no }}</span>
          @else
            <span class="muted">Not raised from a visit</span>
          @endif
        </dd>
        <dt>Made up of</dt>
        <dd>
          {{ \App\Support\HospitalSettings::money($inv->subtotal) }} of work
          @if(bccomp((string) $inv->discount, '0', 2) > 0)
            · less {{ \App\Support\HospitalSettings::money($inv->discount) }} discount
          @endif
          @if(bccomp((string) $inv->tax_total, '0', 2) > 0)
            · plus {{ \App\Support\HospitalSettings::money($inv->tax_total) }} tax
          @endif
        </dd>
        <dt>Raised</dt><dd>{{ $inv->created_at?->format('j M Y · H:i') ?? '—' }}</dd>
      </dl>

      {{-- What it is FOR. The single commonest question asked of a bill, and
           the row could only say what it came to. --}}
      <x-ui.peek-trail title="What is on it" :rows="$this->peekedItems"
                       empty="Nothing has been put on this invoice.">
        @foreach($this->peekedItems as $line)
          <li wire:key="inv-peek-line-{{ $line->id }}">
            <span class="tb-peek-step">
              <span class="tb-mv-qty">{{ \App\Support\HospitalSettings::money($line->line_total) }}</span>
              {{ $line->description }}
            </span>
            <span class="tb-peek-meta">
              {{ $line->quantity }} × {{ \App\Support\HospitalSettings::money($line->unit_price) }}
              @if($line->tax_exempt) · tax exempt @endif
            </span>
          </li>
        @endforeach
        @if($this->itemsNotShown() > 0)
          <li>
            <span class="tb-peek-meta">
              …and {{ $this->itemsNotShown() }} more {{ Str::plural('line', $this->itemsNotShown()) }} on the invoice itself.
            </span>
          </li>
        @endif
      </x-ui.peek-trail>

      <x-ui.peek-trail title="What has been paid" :rows="$this->peekedPayments"
                       empty="Nothing has been paid against this invoice yet.">
        @foreach($this->peekedPayments as $pay)
          <li wire:key="inv-peek-pay-{{ $pay->id }}">
            <span class="tb-peek-step">
              <span class="tb-mv-qty is-in">{{ \App\Support\HospitalSettings::money($pay->amount) }}</span>
              {{ $pay->method->label() }} · left {{ \App\Support\HospitalSettings::money($pay->balance_after) }}
            </span>
            <span class="tb-peek-meta">
              {{ $pay->created_at?->format('j M · H:i') }}@if($pay->receivedBy) · {{ $pay->receivedBy->name }} @endif
            </span>
            @if($pay->reference)<span class="tb-peek-note mono">{{ $pay->reference }}</span>@endif
          </li>
        @endforeach
      </x-ui.peek-trail>
    </div>

    <x-ui.peek-foot :href="route('admin.invoices.show', $inv)" label="Open the invoice" icon="fa-file-invoice-dollar">
      <a class="btn-tb btn-tb-ghost" href="{{ route('admin.invoices.pdf', $inv) }}" target="_blank" rel="noopener">
        <i class="fas fa-file-pdf" aria-hidden="true"></i> Printable
      </a>
      @if($inv->patient)
        <a class="btn-tb btn-tb-ghost" wire:navigate href="{{ route('admin.patients.show', $inv->patient) }}">
          <i class="fas fa-user" aria-hidden="true"></i> The patient
        </a>
      @endif
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
