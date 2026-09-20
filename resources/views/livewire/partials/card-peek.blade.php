{{-- One card, over whatever list you came from.

     A card desk is asked four questions and the row answers none of them in
     full: what is on it, what may still be spent, who else may spend it, and
     what it was last spent on. Going to the card's own page to answer them
     loses the reader's place in a list they were working down, so the answers
     come to the list instead. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked ? 'Card '.$this->peeked->masked() : 'Card'">
  @if($this->peeked)
    @php($card = $this->peeked)
    <div class="tb-modal-body">
      <div class="tb-peek-head">
        <div>
          <div class="tb-peek-when">{{ $card->patient?->full_name ?? 'Unassigned' }}</div>
          <div class="tb-peek-time">
            @if($card->provider)
              {{ $card->provider->name }}@if($card->member_no) · member {{ $card->member_no }}@endif
            @else
              The patient’s own card
            @endif
          </div>
        </div>
        <div class="tb-peek-badges">
          <x-ui.badge :tone="$card->status->badge()">{{ $card->status->label() }}</x-ui.badge>
          @if($card->isExpired())<x-ui.badge tone="danger">Expired</x-ui.badge>@endif
        </div>
      </div>

      {{-- The money, first and in the order it is asked about: what is on it,
           what may still be spent, what is owed. --}}
      <div class="tb-peek-figs">
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n {{ bccomp((string) $card->balance, '0', 2) < 0 ? 'is-bad' : '' }}">
            {{ \App\Support\HospitalSettings::money($card->balance) }}
          </span>
          <span class="tb-peek-fig-l">On the card</span>
        </div>
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n">{{ \App\Support\HospitalSettings::money($card->spendable()) }}</span>
          <span class="tb-peek-fig-l">Still spendable</span>
        </div>
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n {{ bccomp($card->debt(), '0', 2) > 0 ? 'is-bad' : '' }}">
            {{ \App\Support\HospitalSettings::money($card->debt()) }}
          </span>
          <span class="tb-peek-fig-l">{{ $card->isInsurance() ? 'For the insurer to clear' : 'Owed to the hospital' }}</span>
        </div>
      </div>

      <dl class="tb-peek-facts">
        <dt>Number</dt><dd class="mono">{{ $card->masked() }}</dd>
        <dt>Credit</dt>
        <dd>
          @if($card->accepts_credit)
            Up to {{ \App\Support\HospitalSettings::money($card->max_credit) }}
          @else
            <span class="muted">Not allowed — it can only spend what is on it</span>
          @endif
        </dd>
        <dt>Expires</dt>
        <dd @class(['tb-peek-bad' => $card->isExpired()])>
          @if($card->expiry)
            {{ $card->expiry->format('j M Y') }} · {{ $card->expiry->diffForHumans() }}
          @else
            <span class="muted">No expiry</span>
          @endif
        </dd>
        <dt>Issued</dt>
        <dd>
          {{ $card->created_at?->format('j M Y') ?? '—' }}@if($card->issuer) by {{ $card->issuer->name }}@endif
        </dd>
      </dl>

      {{-- Who else may spend it. On a family card this is the question asked
           before anything is charged, and the row only ever said "+2 others". --}}
      <div class="tb-peek-sec">Who may spend on it</div>
      <ul class="tb-peek-trail">
        <li>
          <span class="tb-peek-step">
            <span class="tb-fw-500">{{ $card->patient?->full_name ?? '—' }}</span> · cardholder
          </span>
          <span class="tb-peek-meta">{{ $card->patient?->patient_no }}</span>
        </li>
        @foreach($this->peekedHolders as $holder)
          <li wire:key="peek-holder-{{ $holder->id }}">
            <span class="tb-peek-step">
              <span class="tb-fw-500">{{ $holder->patient?->full_name ?? '—' }}</span>
              · {{ $holder->relationship->label() }}
              <x-ui.badge :tone="$holder->status->badge()">{{ $holder->status->label() }}</x-ui.badge>
            </span>
            <span class="tb-peek-meta">
              {{ $holder->patient?->patient_no }}@if($holder->revoked_at) · revoked {{ $holder->revoked_at->format('j M Y') }}@endif
            </span>
          </li>
        @endforeach
      </ul>

      {{-- How the balance got to the figure at the top. --}}
      <div class="tb-peek-sec">Last few entries</div>
      @if($this->peekedRecords->isNotEmpty())
        <ul class="tb-peek-trail">
          @foreach($this->peekedRecords as $rec)
            <li wire:key="peek-rec-{{ $rec->id }}">
              <span class="tb-peek-step">
                <span @class(['tb-mv-qty', 'is-in' => $rec->type === \App\Enums\CardEntryType::Credit])>
                  {{ $rec->type === \App\Enums\CardEntryType::Credit ? '+' : '−' }}{{ \App\Support\HospitalSettings::money($rec->amount) }}
                </span>
                {{ $rec->description ?: $rec->type->label() }} · left {{ \App\Support\HospitalSettings::money($rec->balance_after) }}
              </span>
              <span class="tb-peek-meta">
                {{ $rec->created_at?->format('j M · H:i') }}@if($rec->createdBy) · {{ $rec->createdBy->name }}@endif
                {{-- On a family card, who it was spent ON is not who the card
                     belongs to, and that is the line a dispute turns on. --}}
                @if($rec->patient_id && $rec->patient_id !== $card->patient_id)
                  · for {{ $rec->patient?->full_name }}
                @endif
              </span>
              @if($rec->reference)<span class="tb-peek-note mono">{{ $rec->reference }}</span>@endif
            </li>
          @endforeach
        </ul>
      @else
        <p class="tb-out-none">Nothing has been put on or taken off this card yet.</p>
      @endif
    </div>

    <div class="tb-modal-foot tb-peek-foot">
      @if($card->patient)
        <a class="btn-tb btn-tb-ghost" wire:navigate href="{{ route('admin.patients.show', $card->patient) }}">
          <i class="fas fa-user" aria-hidden="true"></i> The patient
        </a>
      @endif
      <span class="tb-peek-gap"></span>
      <button type="button" class="btn-tb btn-tb-ghost" wire:click="closePeek">Close</button>
      <a class="btn-tb btn-tb-primary" wire:navigate href="{{ route('admin.cards.show', $card->uuid) }}">
        <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record
      </a>
    </div>
  @endif
</x-ui.modal>
