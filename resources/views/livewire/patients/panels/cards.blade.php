<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-wallet" aria-hidden="true"></i> Cards</span>
    <button type="button" class="btn-tb btn-tb-sm" wire:click="openIssue">
      <i class="fas fa-plus" aria-hidden="true"></i> Issue card
    </button>
  </div>

  <div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Prepaid cards and their ledgers</caption>
      <thead>
        <tr>
          <th>Card</th>
          <th>Insurer</th>
          <th>Status</th>
          <th class="tb-text-right">Balance</th>
          <th class="tb-text-right">Credit limit</th>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        @forelse($this->cards as $card)
          <tr wire:key="card-{{ $card->id }}">
            <td class="rowlink mono">
              <a wire:navigate href="{{ route('admin.cards.show', $card->uuid) }}">{{ $card->masked() }}</a>
              @if($card->holders_count > 0)
                <span class="muted" style="font-size:.78rem;">
                  shared with {{ $card->holders_count }} {{ Str::plural('other', $card->holders_count) }}
                </span>
              @endif
            </td>
            <td>
              @if($card->provider)
                {{ $card->provider->name }}
                @if($card->member_no)<span class="muted mono" style="font-size:.78rem;">{{ $card->member_no }}</span>@endif
              @else
                <span class="muted">Their own</span>
              @endif
            </td>
            <td>
              <x-ui.badge :tone="$card->status->badge()">{{ $card->status->label() }}</x-ui.badge>
              @if($card->isExpired())<x-ui.badge tone="danger">Expired</x-ui.badge>@endif
            </td>
            <td class="tb-text-right">
              <x-ui.money :amount="$card->balance"
                          :class="bccomp((string) $card->balance, '0', 2) < 0 ? 'tb-danger' : 'tb-primary'" />
            </td>
            <td class="tb-text-right muted">
              @if($card->accepts_credit)<x-ui.money :amount="$card->max_credit" />@else — @endif
            </td>
            <td class="tb-text-right tb-nowrap">
              <x-ui.icon-button label="Top up card {{ $card->masked() }}" icon="fa-arrow-up"
                                wire:click="openTxn({{ $card->id }}, 'credit')" />
              <x-ui.icon-button label="Charge card {{ $card->masked() }}" icon="fa-arrow-down" variant="danger"
                                wire:click="openTxn({{ $card->id }}, 'debit')" />
              {{-- Terms, holders and the insurer live on the card's own page.
                   One control each, in one place (docs/cards.md). --}}
              <a wire:navigate href="{{ route('admin.cards.show', $card->uuid) }}"
                 class="btn-tb btn-tb-ghost btn-tb-icon" aria-label="Open card {{ $card->masked() }}">
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
              </a>
            </td>
          </tr>
          @if($card->records->isNotEmpty())
            <tr wire:key="ledger-{{ $card->id }}">
              <td colspan="6">
                <details>
                  <summary>Ledger ({{ $card->records->count() }})</summary>
                  <div class="tb-table-wrap tb-mt-2">
                    <table class="tb-table">
                      <caption class="sr-only">Ledger for card {{ $card->masked() }}</caption>
                      <thead>
                        <tr>
                          <th>When</th><th>Type</th>
                          <th class="tb-text-right">Amount</th><th class="tb-text-right">Balance</th>
                          <th>Note</th><th>Spent on</th><th>By</th>
                        </tr>
                      </thead>
                      <tbody>
                        @foreach($card->records as $record)
                          <tr wire:key="entry-{{ $record->id }}">
                            <td class="tb-nowrap">{{ $record->created_at?->format('d M Y H:i') }}</td>
                            <td>
                              <x-ui.badge :tone="$record->type === \App\Enums\CardEntryType::Credit ? 'success' : 'warn'">
                                {{ $record->type->label() }}
                              </x-ui.badge>
                            </td>
                            <td class="tb-text-right">
                              {{ $record->type === \App\Enums\CardEntryType::Credit ? '+' : '−' }}<x-ui.money :amount="$record->amount" />
                            </td>
                            <td class="tb-text-right"><x-ui.money :amount="$record->balance_after" /></td>
                            <td>{{ $record->description ?? '—' }}</td>
                            <td class="muted">{{ $record->patient?->full_name ?? '—' }}</td>
                            <td class="muted">{{ $record->createdBy?->name ?? '—' }}</td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                </details>
              </td>
            </tr>
          @endif
        @empty
          <tr><td colspan="6"><x-ui.empty icon="fa-wallet" noun="cards issued" /></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- ── Slide-over: issue a card ────────────────────────────── --}}
  <x-ui.modal size="md" show="showIssue" title="Issue a card">
    @if($showIssue)
      <form wire:submit="issue" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Expiry" for="card-expiry" name="expiry" hint="Optional — leave blank for a card that never expires.">
            <input id="card-expiry" type="date" wire:model="expiry" class="tb-input">
          </x-ui.field>
          <div class="tb-form-group">
            <label class="tb-check-group">
              <input type="checkbox" wire:model.live="accepts_credit"> Allow credit / overdraft
            </label>
          </div>
          @if($accepts_credit)
            <x-ui.field label="Credit limit" for="card-limit" name="max_credit">
              <input id="card-limit" type="number" step="0.01" min="0" wire:model="max_credit" class="tb-input" placeholder="0.00">
            </x-ui.field>
          @endif

          <x-ui.field label="Insurer" for="card-insurer" name="insurance_provider_id"
                      hint="An insurer’s card is meant to run into debt — the company clears it against the float they hold with you.">
            <select id="card-insurer" wire:model.live="insurance_provider_id" class="tb-select">
              <option value="">The patient’s own card</option>
              @foreach($this->insurers as $insurer)
                <option value="{{ $insurer->id }}">{{ $insurer->name }}</option>
              @endforeach
            </select>
          </x-ui.field>

          @if($insurance_provider_id)
            <x-ui.field label="Member number" for="card-member" name="member_no"
                        hint="The number the insurer knows them by. It appears on the usage statement you send back.">
              <input id="card-member" type="text" wire:model="member_no" class="tb-input" maxlength="64">
            </x-ui.field>
          @endif
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="issue">
            <span wire:loading.remove wire:target="issue"><i class="fas fa-check" aria-hidden="true"></i> Issue card</span>
            <span wire:loading wire:target="issue"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Issuing…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Slide-over: credit / debit (pessimistic) ────────────── --}}
  <x-ui.modal size="md" show="showTxn" :title="$txnType === 'debit' ? 'Charge card' : 'Top up card'">
    @if($showTxn)
      <form wire:submit="postTxn" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Amount" for="txn-amount" name="amount" required>
            <input id="txn-amount" type="number" step="0.01" min="0.01" wire:model="amount" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Note" for="txn-note" name="description">
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
</div>
