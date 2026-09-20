@php($card = $this->card)
<div>
  <x-ui.breadcrumb :crumbs="['Cards' => route('admin.cards.index'), $card->masked() => null]" />

  {{-- ── What this card is ───────────────────────────────────────────── --}}
  <div class="tb-card tb-mb-4">
    <div class="tb-card-header">
      <span class="tb-card-title">
        <i class="fas fa-credit-card" aria-hidden="true"></i>
        <span class="mono">{{ $card->masked() }}</span>
        <x-ui.badge :tone="$card->status->badge()">{{ $card->status->label() }}</x-ui.badge>
        @if($card->isExpired())<x-ui.badge tone="danger">Expired</x-ui.badge>@endif
      </span>

      @can('update', $card)
        <div class="tb-toolbar">
          <button type="button" class="btn-tb btn-tb-sm" wire:click="openTxn('credit')">
            <i class="fas fa-arrow-up" aria-hidden="true"></i> Top up
          </button>
          <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="openTxn('debit')">
            <i class="fas fa-arrow-down" aria-hidden="true"></i> Charge
          </button>
          <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="openTerms">
            <i class="fas fa-sliders" aria-hidden="true"></i> Terms
          </button>
        </div>
      @endcan
    </div>

    <div class="tb-card-body">
      <div class="tb-stats-grid">
        <x-dash.stat icon="fa-wallet"
                     :tone="bccomp((string) $card->balance, '0', 2) < 0 ? 'bad' : 'ok'"
                     :value="\App\Support\HospitalSettings::money($card->balance)"
                     label="Balance"
                     :sub="bccomp($card->debt(), '0', 2) > 0 ? 'Owed to the hospital' : 'Available to spend'" />

        <x-dash.stat icon="fa-hand-holding-dollar"
                     :value="$card->accepts_credit ? \App\Support\HospitalSettings::money($card->max_credit) : 'None'"
                     label="Credit limit"
                     :sub="$card->accepts_credit ? \App\Support\HospitalSettings::money($card->spendable()).' still spendable' : 'This card cannot go into debt'" />

        <x-dash.stat icon="fa-user"
                     :value="$card->patient?->full_name ?? '—'"
                     label="Issued to"
                     :sub="$card->patient?->patient_no" />

        <x-dash.stat icon="fa-shield-heart"
                     :value="$card->provider->name ?? 'Their own'"
                     label="Insurer"
                     :sub="$card->member_no ? 'Member '.$card->member_no : ($card->isInsurance() ? 'No member number' : 'A prepaid card, not an insurance one')" />
      </div>

      {{-- The balance is a cache of the ledger below it, so it is proved
           rather than asserted. Silence when the two agree. --}}
      @unless($this->proof['agrees'])
        <p class="tb-gate-block tb-mt-3">
          <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
          This card says {{ \App\Support\HospitalSettings::money($this->proof['balance']) }}
          but its ledger adds up to {{ \App\Support\HospitalSettings::money($this->proof['ledger']) }}.
          Nothing here will be right until that is explained.
        </p>
      @endunless

      @if($card->isInsurance() && bccomp($card->debt(), '0', 2) > 0)
        <div class="tb-next tb-mt-3">
          <span class="tb-next-say">
            {{ $card->provider?->name }} owes {{ \App\Support\HospitalSettings::money($card->debt()) }} on this card.
          </span>
          @can('update', $card)
            <button type="button" class="btn-tb btn-tb-sm btn-tb-primary"
                    wire:click="settle" wire:loading.attr="disabled" wire:target="settle">
              <span wire:loading.remove wire:target="settle">
                <i class="fas fa-money-bill-transfer" aria-hidden="true"></i> Clear from their float
              </span>
              <span wire:loading wire:target="settle">Clearing…</span>
            </button>
          @endcan
        </div>
      @endif
    </div>
  </div>

  {{-- ── Who may spend on it ─────────────────────────────────────────── --}}
  <div class="tb-card tb-mb-4">
    <div class="tb-card-header">
      <span class="tb-card-title"><i class="fas fa-people-roof" aria-hidden="true"></i> Who may use this card</span>
      @can('update', $card)
        <button type="button" class="btn-tb btn-tb-sm" wire:click="openHolder">
          <i class="fas fa-user-plus" aria-hidden="true"></i> Add someone
        </button>
      @endcan
    </div>

    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">People who may spend on this card</caption>
        <thead>
          <tr><th>Person</th><th>Relationship</th><th>Status</th><th>Added by</th><th><span class="sr-only">Actions</span></th></tr>
        </thead>
        <tbody>
          {{-- The primary holder is the card, not a row on it, so it is shown
               without any control to take them off. --}}
          <tr>
            <td style="font-weight:500;">
              {{ $card->patient?->full_name ?? '—' }}
              <span class="muted mono" style="font-size:.78rem;">{{ $card->patient?->patient_no }}</span>
            </td>
            <td class="muted">Cardholder</td>
            <td><x-ui.badge tone="badge-active">Active</x-ui.badge></td>
            <td class="muted">{{ $card->issuer?->name ?? '—' }}</td>
            <td class="muted" style="text-align:right;font-size:.78rem;">The card is theirs</td>
          </tr>

          @foreach($this->holders as $holder)
            <tr wire:key="holder-{{ $holder->id }}">
              <td>
                {{ $holder->patient?->full_name ?? '—' }}
                <span class="muted mono" style="font-size:.78rem;">{{ $holder->patient?->patient_no }}</span>
              </td>
              <td>{{ $holder->relationship->label() }}</td>
              <td>
                <x-ui.badge :tone="$holder->status->badge()">{{ $holder->status->label() }}</x-ui.badge>
                @if($holder->revoked_at)
                  <span class="muted" style="font-size:.78rem;">{{ $holder->revoked_at->format('d M Y') }}</span>
                @endif
              </td>
              <td class="muted">{{ $holder->addedBy?->name ?? '—' }}</td>
              <td style="text-align:right;">
                @can('update', $card)
                  @if($holder->status->canSpend())
                    <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm"
                            wire:click="revokeHolder({{ $holder->id }})"
                            wire:confirm="Take {{ $holder->patient?->full_name }} off this card? What they have already spent stays on the ledger.">
                      <i class="fas fa-user-minus" aria-hidden="true"></i> Remove
                    </button>
                  @else
                    <span class="muted" style="font-size:.78rem;">Cannot spend</span>
                  @endif
                @endcan
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  {{-- ── Every movement ──────────────────────────────────────────────── --}}
  <div class="tb-card">
    <div class="tb-card-header">
      <span class="tb-card-title"><i class="fas fa-receipt" aria-hidden="true"></i> Ledger</span>
      <a wire:navigate href="{{ route('admin.card-records.index', ['q' => $card->patient?->patient_no]) }}"
         class="btn-tb btn-tb-sm btn-tb-ghost">
        <i class="fas fa-list" aria-hidden="true"></i> All card records
      </a>
    </div>

    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Every movement on card {{ $card->masked() }}</caption>
        <thead>
          <tr>
            <th>When</th><th>What</th><th>Spent on</th>
            <th style="text-align:right;">Amount</th><th style="text-align:right;">Balance</th><th>By</th>
          </tr>
        </thead>
        <tbody>
          @forelse($this->records as $record)
            <tr wire:key="rec-{{ $record->id }}">
              <td class="tb-nowrap muted">{{ $record->created_at?->format('d M Y H:i') }}</td>
              <td>
                {{ $record->description ?? $record->type->label() }}
                @if($record->reference)
                  <span class="muted mono" style="font-size:.78rem;">{{ $record->reference }}</span>
                @endif
              </td>
              <td class="muted">{{ $record->patient?->full_name ?? '—' }}</td>
              <td style="text-align:right;" class="mono {{ $record->type === \App\Enums\CardEntryType::Credit ? 'tb-primary' : 'tb-danger' }}">
                {{ $record->type === \App\Enums\CardEntryType::Credit ? '+' : '−' }}{{ \App\Support\HospitalSettings::money($record->amount) }}
              </td>
              <td style="text-align:right;" class="mono">{{ \App\Support\HospitalSettings::money($record->balance_after) }}</td>
              <td class="muted">{{ $record->createdBy?->name ?? '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="6"><x-ui.empty icon="fa-receipt" noun="movements" compact /></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    {{ $this->records->links(data: ['perPageOptions' => []]) }}
  </div>

  {{-- ── Top up / charge ─────────────────────────────────────────────── --}}
  <x-ui.modal size="md" show="showTxn" :title="$txnType === 'debit' ? 'Charge this card' : 'Top up this card'">
    @if($showTxn)
      <form wire:submit="postTxn" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Amount" for="txn-amount" name="amount" required
                      :hint="'Balance now: '.\App\Support\HospitalSettings::money($card->balance)">
            <input id="txn-amount" type="number" step="0.01" min="0.01" wire:model="amount" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="What for" for="txn-note" name="description"
                      hint="Whoever reads this ledger next will only have these words to go on.">
            <input id="txn-note" type="text" wire:model="description" class="tb-input" maxlength="255">
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb {{ $txnType === 'debit' ? 'btn-tb-danger' : 'btn-tb-primary' }}"
                  wire:loading.attr="disabled" wire:target="postTxn">
            <span wire:loading.remove wire:target="postTxn">
              <i class="fas fa-check" aria-hidden="true"></i> {{ $txnType === 'debit' ? 'Charge card' : 'Top up card' }}
            </span>
            <span wire:loading wire:target="postTxn"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Posting…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Terms ───────────────────────────────────────────────────────── --}}
  <x-ui.modal size="md" show="showTerms" title="What this card may do">
    @if($showTerms)
      <form wire:submit="saveTerms" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Status" for="terms-status" name="status" required
                      hint="A card that is not active takes no money in or out.">
            <select id="terms-status" wire:model="status" class="tb-select">
              @foreach($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
            </select>
          </x-ui.field>

          <x-ui.field label="Expires" for="terms-expiry" name="expiry" hint="Leave blank for a card that never expires.">
            <input id="terms-expiry" type="date" wire:model="expiry" class="tb-input">
          </x-ui.field>

          <div class="tb-form-group">
            <label class="tb-check-group">
              <input type="checkbox" wire:model.live="accepts_credit"> Let this card go into debt
            </label>
          </div>

          @if($accepts_credit)
            <x-ui.field label="How far into debt" for="terms-limit" name="max_credit"
                        hint="The most it may owe at any moment.">
              <input id="terms-limit" type="number" step="0.01" min="0" wire:model="max_credit" class="tb-input" placeholder="0.00">
            </x-ui.field>
          @endif

          <x-ui.field label="Insurer" for="terms-insurer" name="insurance_provider_id"
                      hint="An insurer’s card runs into debt and the company clears it against their float.">
            <select id="terms-insurer" wire:model.live="insurance_provider_id" class="tb-select">
              <option value="">The patient’s own card</option>
              @foreach($this->insurers as $insurer)
                <option value="{{ $insurer->id }}">{{ $insurer->name }}</option>
              @endforeach
            </select>
          </x-ui.field>

          @if($insurance_provider_id)
            <x-ui.field label="Member number" for="terms-member" name="member_no"
                        hint="The number the insurer knows this member by — it appears on their usage statement.">
              <input id="terms-member" type="text" wire:model="member_no" class="tb-input" maxlength="64">
            </x-ui.field>
          @endif
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveTerms">
            <span wire:loading.remove wire:target="saveTerms"><i class="fas fa-check" aria-hidden="true"></i> Save</span>
            <span wire:loading wire:target="saveTerms"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Add a holder ────────────────────────────────────────────────── --}}
  <x-ui.modal size="md" show="showHolder" title="Let someone else use this card">
    @if($showHolder)
      <form wire:submit="addHolder" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Patient" name="holder_patient_id" required
                      hint="They will be able to spend the whole balance, so put on only the people the cardholder means to.">
            <livewire:ui.select-search resource="patients" name="holder_patient_id"
                                       :selected="$holder_patient_id" placeholder="Search patients…"
                                       :key="'holder-pick-'.$formNonce" />
          </x-ui.field>

          <x-ui.field label="Relationship" for="holder-rel" name="relationship" required>
            <select id="holder-rel" wire:model="relationship" class="tb-select">
              @foreach($relationships as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
            </select>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="addHolder">
            <span wire:loading.remove wire:target="addHolder"><i class="fas fa-check" aria-hidden="true"></i> Add to card</span>
            <span wire:loading wire:target="addHolder"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Adding…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
