{{-- One line of a card ledger, over the ledger.

     A reconciliation asks three things of a line: what it was, which card it
     came off, and — on a family or insurance card — who it was actually spent
     on. The row shows a description and a figure, and the only way in was a
     jump to the card, which answers none of them. --}}
<x-ui.modal show="showPeek" size="md" autosaves title="Ledger entry">
  @if($this->peeked)
    @php($rec = $this->peeked)
    @php($isCredit = $rec->type === \App\Enums\CardEntryType::Credit)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="($isCredit ? '+' : '−').\App\Support\HospitalSettings::money($rec->amount)"
                      :sub="$rec->created_at?->format('l j F Y · H:i')">
        <x-ui.badge :tone="$isCredit ? 'success' : 'danger'">{{ $rec->type->label() }}</x-ui.badge>
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => $isCredit ? 'Put on' : 'Taken off',
         'value' => \App\Support\HospitalSettings::money($rec->amount)],
        ['label' => 'Card stood at',
         'value' => \App\Support\HospitalSettings::money($rec->balance_after),
         'bad' => bccomp((string) $rec->balance_after, '0', 2) < 0],
      ]" />

      <dl class="tb-peek-facts">
        <dt>What it was</dt><dd>{{ $rec->description ?: $rec->type->label() }}</dd>
        <dt>Card</dt>
        <dd>
          @if($rec->card)
            <span class="mono">{{ $rec->card->masked() }}</span>
            @if($rec->card->provider)
              <span class="muted">· {{ $rec->card->provider->name }}</span>
            @else
              <span class="muted">· the patient’s own</span>
            @endif
          @else
            <span class="muted">—</span>
          @endif
        </dd>
        <dt>Cardholder</dt><dd>{{ $rec->card?->patient?->full_name ?? '—' }}</dd>
        {{-- On a family card the person it was spent ON is not the person the
             card belongs to, and that is the line a dispute turns on. --}}
        <dt>Spent on</dt>
        <dd>
          @if($rec->patient_id && $rec->patient_id !== $rec->card?->patient_id)
            <span class="tb-fw-500">{{ $rec->patient?->full_name ?? '—' }}</span>
            <span class="muted">— not the cardholder</span>
          @else
            <span class="muted">The cardholder</span>
          @endif
        </dd>
        <dt>Reference</dt><dd class="mono">{{ $rec->reference ?: '—' }}</dd>
        <dt>Entered by</dt><dd>{{ $rec->createdBy?->name ?? '—' }}</dd>
        @if($rec->settlement)
          <dt>Cleared by</dt>
          <dd>
            The insurer’s {{ $rec->settlement->type->label() }} of
            {{ \App\Support\HospitalSettings::money($rec->settlement->amount) }}
          </dd>
        @endif
      </dl>

      <p class="tb-out-none">
        This line cannot be edited or deleted. A ledger that can be rewritten is not one —
        a mistake is corrected by another line that says so.
      </p>
    </div>

    <x-ui.peek-foot :href="$rec->card ? route('admin.cards.show', $rec->card->uuid) : null"
                    label="The card" icon="fa-credit-card">
      @if($rec->card?->patient)
        <a class="btn-tb btn-tb-ghost" wire:navigate href="{{ route('admin.patients.show', $rec->card->patient) }}">
          <i class="fas fa-user" aria-hidden="true"></i> The patient
        </a>
      @endif
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
