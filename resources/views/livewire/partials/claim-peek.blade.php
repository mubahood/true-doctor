{{-- One claim, over the claims list.

     A claims desk asks three things of every row: what was it raised against,
     how much of that invoice does it cover, and where has it got to. The row
     answers none of them, and the claim's own page answered all three at the
     cost of the reader's place in the list. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked ? 'Claim '.$this->peeked->claim_no : 'Claim'">
  @if($this->peeked)
    @php($claim = $this->peeked)
    <div class="tb-modal-body">
      <div class="tb-peek-head">
        <div>
          <div class="tb-peek-when">{{ $claim->patient?->full_name ?? '—' }}</div>
          <div class="tb-peek-time">{{ $claim->provider?->name ?? 'No provider' }}</div>
        </div>
        <div class="tb-peek-badges">
          <x-ui.badge :tone="$claim->status->badge()">{{ $claim->status->label() }}</x-ui.badge>
        </div>
      </div>

      {{-- Claimed against billed: the comparison that says whether this claim
           settles the invoice or leaves the patient carrying the rest. --}}
      <div class="tb-peek-figs">
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n">{{ \App\Support\HospitalSettings::money($claim->amount) }}</span>
          <span class="tb-peek-fig-l">Claimed</span>
        </div>
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n">{{ $claim->invoice ? \App\Support\HospitalSettings::money($claim->invoice->total) : '—' }}</span>
          <span class="tb-peek-fig-l">Invoice total</span>
        </div>
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n {{ $claim->invoice && bccomp((string) $claim->invoice->balance, '0', 2) > 0 ? 'is-bad' : '' }}">
            {{ $claim->invoice ? \App\Support\HospitalSettings::money($claim->invoice->balance) : '—' }}
          </span>
          <span class="tb-peek-fig-l">Still unpaid</span>
        </div>
      </div>

      <dl class="tb-peek-facts">
        <dt>Claim no.</dt><dd class="mono">{{ $claim->claim_no }}</dd>
        <dt>Patient no.</dt><dd class="mono">{{ $claim->patient?->patient_no ?? '—' }}</dd>
        <dt>Invoice</dt>
        <dd>
          @if($claim->invoice)
            <span class="mono">{{ $claim->invoice->invoice_no }}</span>
            @if(bccomp((string) $claim->amount, (string) $claim->invoice->total, 2) < 0)
              <span class="muted">— covers part of it</span>
            @endif
          @else
            <span class="muted">—</span>
          @endif
        </dd>
        <dt>Raised</dt><dd>{{ $claim->created_at?->format('j M Y · H:i') ?? '—' }}</dd>
        <dt>Submitted</dt>
        <dd>
          @if($claim->submitted_at)
            {{ $claim->submitted_at->format('j M Y · H:i') }}
          @else
            <span class="muted">Not yet submitted</span>
          @endif
        </dd>
        <dt>Settled</dt>
        <dd>
          @if($claim->resolved_at)
            {{ $claim->resolved_at->format('j M Y · H:i') }}@if($claim->resolvedBy) by {{ $claim->resolvedBy->name }}@endif
          @else
            <span class="muted">Still open</span>
          @endif
        </dd>
      </dl>

      @if($claim->notes)
        <div class="tb-peek-sec">Notes</div>
        <p class="tb-peek-note">{{ $claim->notes }}</p>
      @endif

      {{-- Where it can go next, in the words of the machine that decides it.
           Reading it here saves opening the page to find out there is nothing
           left to do. --}}
      <div class="tb-peek-sec">What happens next</div>
      @if($claim->status->transitionsTo())
        <p class="tb-out-none">
          From {{ $claim->status->label() }} it can go to
          {{ collect($claim->status->transitionsTo())->map(fn ($s) => $s->label())->join(', ', ' or ') }}
          — on the claim's own page.
        </p>
      @else
        <p class="tb-out-none">This claim is closed. Nothing more can be done to it.</p>
      @endif
    </div>

    <div class="tb-modal-foot tb-peek-foot">
      @if($claim->invoice)
        <a class="btn-tb btn-tb-ghost" wire:navigate href="{{ route('admin.invoices.show', $claim->invoice) }}">
          <i class="fas fa-file-invoice" aria-hidden="true"></i> The invoice
        </a>
      @endif
      <span class="tb-peek-gap"></span>
      <button type="button" class="btn-tb btn-tb-ghost" wire:click="closePeek">Close</button>
      <a class="btn-tb btn-tb-primary" wire:navigate href="{{ route('admin.insurance-claims.show', $claim) }}">
        <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record
      </a>
    </div>
  @endif
</x-ui.modal>
