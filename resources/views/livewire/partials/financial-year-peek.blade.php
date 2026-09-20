{{-- One accounting period, over the list.

     Nobody opens a financial year to read its dates back — they open it for
     what was invoiced, what was collected and what is still outstanding. That
     is four figures and a breakdown, which fits in a dialog. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'Financial year'">
  @if($this->peeked && $this->peekedReport)
    @php($y = $this->peeked)
    @php($rep = $this->peekedReport)
    <div class="tb-modal-body">
      <div class="tb-peek-head">
        <div>
          <div class="tb-peek-when">{{ $y->starts_on->format('d M Y') }} — {{ $y->ends_on->format('d M Y') }}</div>
          <div class="tb-peek-time">
            {{ $y->starts_on->diffInDays($y->ends_on) + 1 }} days
            @if($y->contains(now())) · today falls inside it @endif
          </div>
        </div>
        <div class="tb-peek-badges">
          <x-ui.badge :tone="$y->status->badge()">{{ $y->status->label() }}</x-ui.badge>
        </div>
      </div>

      <div class="tb-peek-figs">
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n">{{ \App\Support\HospitalSettings::money($rep['invoices_total']) }}</span>
          <span class="tb-peek-fig-l">Invoiced · {{ $rep['invoice_count'] }}</span>
        </div>
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n">{{ \App\Support\HospitalSettings::money($rep['payments_total']) }}</span>
          <span class="tb-peek-fig-l">Collected · {{ $rep['payment_count'] }}</span>
        </div>
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n {{ bccomp($rep['outstanding'], '0', 2) > 0 ? 'is-bad' : '' }}">
            {{ \App\Support\HospitalSettings::money($rep['outstanding']) }}
          </span>
          <span class="tb-peek-fig-l">Still outstanding</span>
        </div>
      </div>

      <dl class="tb-peek-facts">
        <dt>Posting</dt>
        <dd>
          @if($y->isOpen())
            Open — invoices and payments dated inside it are allowed
          @else
            <span class="tb-peek-bad">Closed — nothing new can be dated inside it</span>
          @endif
        </dd>
        @if(! $y->isOpen())
          <dt>Closed</dt>
          <dd>
            {{ $y->closed_at?->format('j M Y · H:i') ?? '—' }}@if($y->closedBy) by {{ $y->closedBy->name }}@endif
          </dd>
        @endif
      </dl>

      {{-- Where the money actually came in. A period that collected everything
           in cash is a different conversation from one that collected it on
           insurance, and the totals above cannot tell them apart. --}}
      <div class="tb-peek-sec">How it was collected</div>
      @php($methods = collect($rep['by_method'])->filter(fn ($amt) => bccomp((string) $amt, '0', 2) !== 0))
      @if($methods->isNotEmpty())
        <ul class="tb-peek-trail">
          @foreach($methods as $method => $amount)
            <li wire:key="peek-fy-method-{{ $method }}">
              <span class="tb-peek-step">
                <span class="tb-mv-qty is-in">{{ \App\Support\HospitalSettings::money($amount) }}</span>
                {{ \App\Enums\PaymentMethod::options()[$method] ?? $method }}
              </span>
            </li>
          @endforeach
        </ul>
      @else
        <p class="tb-out-none">Nothing has been collected in this period yet.</p>
      @endif
    </div>

    <div class="tb-modal-foot tb-peek-foot">
      @can('manage', \App\Models\FinancialYear::class)
        @if($y->isOpen())
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="close({{ $y->id }})"
                  wire:confirm="Close this period? New invoices dated within it will be blocked.">
            <i class="fas fa-lock" aria-hidden="true"></i> Close the period
          </button>
        @else
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="reopen({{ $y->id }})"
                  wire:confirm="Reopen this closed period? Postings into it will be allowed again.">
            <i class="fas fa-lock-open" aria-hidden="true"></i> Reopen it
          </button>
        @endif
      @endcan
      <span class="tb-peek-gap"></span>
      <button type="button" class="btn-tb btn-tb-ghost" wire:click="closePeek">Close</button>
      <a class="btn-tb btn-tb-primary" wire:navigate href="{{ route('admin.financial-years.show', $y) }}">
        <i class="fas fa-chart-column" aria-hidden="true"></i> Full report
      </a>
    </div>
  @endif
</x-ui.modal>
