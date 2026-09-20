@php($provider = $this->provider)
@php($usage = $this->usage)
<div>
  <x-ui.breadcrumb :crumbs="['Insurance providers' => route('admin.insurance-providers.index'), $provider->name => null]" />

  {{-- ── The float ───────────────────────────────────────────────────── --}}
  <div class="tb-card tb-mb-4">
    <div class="tb-card-header">
      <span class="tb-card-title">
        <i class="fas fa-shield-heart" aria-hidden="true"></i> {{ $provider->name }}
        @unless($provider->is_active)<x-ui.badge tone="neutral">Inactive</x-ui.badge>@endunless
      </span>

      @if($this->mayManage)
        <div class="tb-toolbar">
          <button type="button" class="btn-tb btn-tb-sm" wire:click="openDeposit">
            <i class="fas fa-plus" aria-hidden="true"></i> Record a deposit
          </button>
          <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="openAdjust">
            <i class="fas fa-pen" aria-hidden="true"></i> Correct the float
          </button>
        </div>
      @endif
    </div>

    <div class="tb-card-body">
      <div class="tb-stats-grid">
        <x-dash.stat icon="fa-vault" tone="ok"
                     :value="\App\Support\HospitalSettings::money($provider->float_balance)"
                     label="Float held" sub="Deposited, not yet spent" />
        <x-dash.stat icon="fa-hand-holding-dollar"
                     :tone="bccomp($provider->outstanding(), '0', 2) > 0 ? 'bad' : ''"
                     :value="\App\Support\HospitalSettings::money($provider->outstanding())"
                     label="Members owe" sub="Across every card they stand behind" />
        <x-dash.stat icon="fa-credit-card" :value="$this->cards->count()" label="Member cards" />
        <x-dash.stat icon="fa-hand-holding-heart"
                     :value="\App\Support\HospitalSettings::money($provider->default_credit_limit)"
                     label="Default limit" sub="What a new member card starts with" />
      </div>

      @unless($this->proof['agrees'])
        <p class="tb-gate-block tb-mt-3">
          <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
          The float says {{ \App\Support\HospitalSettings::money($this->proof['float']) }}
          but its ledger adds up to {{ \App\Support\HospitalSettings::money($this->proof['ledger']) }}.
        </p>
      @endunless

      {{-- The one button this page exists for, beside the figure it acts on. --}}
      @if($this->mayManage && bccomp($provider->outstanding(), '0', 2) > 0)
        <div class="tb-next tb-mt-3">
          @if(bccomp((string) $provider->float_balance, '0', 2) > 0)
            <span class="tb-next-say">
              Clear what the members owe out of the float — oldest debt first, as far as it goes.
            </span>
            <button type="button" class="btn-tb btn-tb-sm btn-tb-primary"
                    wire:click="settleAll" wire:loading.attr="disabled" wire:target="settleAll"
                    wire:confirm="Clear every member card this insurer stands behind, out of their float?">
              <span wire:loading.remove wire:target="settleAll">
                <i class="fas fa-money-bill-transfer" aria-hidden="true"></i> Clear the members
              </span>
              <span wire:loading wire:target="settleAll">Clearing…</span>
            </button>
          @else
            <span class="tb-next-block">
              <i class="fas fa-lock" aria-hidden="true"></i>
              The float is empty — record a deposit before anything can be cleared.
            </span>
          @endif
        </div>
      @endif
    </div>
  </div>

  {{-- ── The usage statement ─────────────────────────────────────────── --}}
  <div class="tb-card tb-mb-4">
    <div class="tb-card-header">
      <span class="tb-card-title"><i class="fas fa-file-invoice" aria-hidden="true"></i> Usage</span>
      <div class="tb-toolbar">
        <x-ui.field label="From" for="use-from" name="from">
          <input id="use-from" type="date" wire:model.live="from" class="tb-input">
        </x-ui.field>
        <x-ui.field label="To" for="use-to" name="to">
          <input id="use-to" type="date" wire:model.live="to" class="tb-input">
        </x-ui.field>
        <a href="{{ route('admin.insurance-providers.usage', [$provider, 'from' => $from, 'to' => $to]) }}"
           target="_blank" rel="noopener" class="btn-tb btn-tb-sm btn-tb-ghost">
          <i class="fas fa-file-pdf" aria-hidden="true"></i> Statement PDF
        </a>
      </div>
    </div>

    <div class="tb-card-body">
      <div class="tb-stats-grid">
        <x-dash.stat icon="fa-cart-shopping"
                     :value="\App\Support\HospitalSettings::money($usage['net'])"
                     label="Spent in this window" :sub="count($usage['members']).' '.Str::plural('member', count($usage['members']))" />
        <x-dash.stat icon="fa-arrow-down-to-bracket"
                     :value="\App\Support\HospitalSettings::money($usage['deposits'])" label="Deposited" />
        <x-dash.stat icon="fa-money-bill-transfer"
                     :value="\App\Support\HospitalSettings::money($usage['settlements'])" label="Cleared onto cards" />
      </div>
    </div>

    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">What each member spent between {{ $usage['from']->format('d M Y') }} and {{ $usage['to']->format('d M Y') }}</caption>
        <thead>
          <tr><th>Member</th><th>Patient no.</th><th>Member no.</th><th>Card</th>
              <th style="text-align:right;">Movements</th><th style="text-align:right;">Spent</th></tr>
        </thead>
        <tbody>
          @forelse($usage['members'] as $row)
            <tr wire:key="use-{{ $loop->index }}">
              <td style="font-weight:500;">{{ $row['patient'] }}</td>
              <td class="muted mono">{{ $row['patient_no'] }}</td>
              <td class="muted mono">{{ $row['member_no'] ?? '—' }}</td>
              <td class="muted mono">{{ $row['card'] }}</td>
              <td style="text-align:right;" class="muted">{{ $row['entries'] }}</td>
              <td style="text-align:right;" class="mono">{{ \App\Support\HospitalSettings::money($row['spent']) }}</td>
            </tr>
          @empty
            <tr><td colspan="6"><x-ui.empty icon="fa-file-invoice" message="Nothing was spent on this insurer’s cards in this window." compact /></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ── Member cards ────────────────────────────────────────────────── --}}
  <div class="tb-card tb-mb-4">
    <div class="tb-card-header">
      <span class="tb-card-title"><i class="fas fa-credit-card" aria-hidden="true"></i> Member cards</span>
      <a wire:navigate href="{{ route('admin.cards.index', ['insurer' => $provider->id]) }}"
         class="btn-tb btn-tb-sm btn-tb-ghost"><i class="fas fa-list" aria-hidden="true"></i> Open in Cards</a>
    </div>

    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Cards this insurer stands behind, those in debt first</caption>
        <thead>
          <tr><th>Card</th><th>Member</th><th>Member no.</th><th>Status</th>
              <th style="text-align:right;">Balance</th><th><span class="sr-only">Actions</span></th></tr>
        </thead>
        <tbody>
          @forelse($this->cards as $card)
            <tr wire:key="card-{{ $card->id }}">
              <td class="rowlink mono">
                <a wire:navigate href="{{ route('admin.cards.show', $card->uuid) }}">{{ $card->masked() }}</a>
              </td>
              <td>{{ $card->patient?->full_name ?? '—' }}</td>
              <td class="muted mono">{{ $card->member_no ?? '—' }}</td>
              <td><x-ui.badge :tone="$card->status->badge()">{{ $card->status->label() }}</x-ui.badge></td>
              <td style="text-align:right;" class="mono {{ bccomp((string) $card->balance, '0', 2) < 0 ? 'tb-danger' : '' }}">
                {{ \App\Support\HospitalSettings::money($card->balance) }}
              </td>
              <td style="text-align:right;">
                @if($this->mayManage && bccomp($card->debt(), '0', 2) > 0 && bccomp((string) $provider->float_balance, '0', 2) > 0)
                  <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="settleOne({{ $card->id }})">
                    <i class="fas fa-money-bill-transfer" aria-hidden="true"></i> Clear
                  </button>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="6"><x-ui.empty icon="fa-credit-card" noun="member cards" compact /></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ── The float's own ledger ──────────────────────────────────────── --}}
  <div class="tb-card">
    <div class="tb-card-header">
      <span class="tb-card-title"><i class="fas fa-receipt" aria-hidden="true"></i> Float ledger</span>
    </div>

    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Every movement of this insurer's float</caption>
        <thead>
          <tr><th>When</th><th>What</th><th>Card</th><th>Reference</th>
              <th style="text-align:right;">Amount</th><th style="text-align:right;">Float after</th><th>By</th></tr>
        </thead>
        <tbody>
          @forelse($this->entries as $entry)
            <tr wire:key="entry-{{ $entry->id }}">
              <td class="tb-nowrap muted">{{ $entry->created_at?->format('d M Y H:i') }}</td>
              <td>
                <x-ui.badge :tone="$entry->type->badge()">{{ $entry->type->label() }}</x-ui.badge>
                @if($entry->notes)<span class="muted" style="font-size:.78rem;">{{ $entry->notes }}</span>@endif
              </td>
              <td class="mono muted">
                @if($entry->card)
                  <a wire:navigate href="{{ route('admin.cards.show', $entry->card->uuid) }}">{{ $entry->card->masked() }}</a>
                @else — @endif
              </td>
              <td class="muted mono">{{ $entry->reference ?? '—' }}</td>
              <td style="text-align:right;" class="mono {{ bccomp($entry->signedAmount(), '0', 2) < 0 ? 'tb-danger' : 'tb-primary' }}">
                {{ bccomp($entry->signedAmount(), '0', 2) < 0 ? '−' : '+' }}{{ \App\Support\HospitalSettings::money(ltrim($entry->signedAmount(), '-')) }}
              </td>
              <td style="text-align:right;" class="mono">{{ \App\Support\HospitalSettings::money($entry->balance_after) }}</td>
              <td class="muted">{{ $entry->createdBy?->name ?? '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="7"><x-ui.empty icon="fa-receipt" message="This insurer has not deposited anything yet." compact /></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    {{ $this->entries->links(data: ['perPageOptions' => []]) }}
  </div>

  {{-- ── Record a deposit ────────────────────────────────────────────── --}}
  <x-ui.modal size="md" show="showDeposit" title="Record a deposit">
    @if($showDeposit)
      <form wire:submit="saveDeposit" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Amount" for="dep-amount" name="amount" required
                      :hint="'Float now: '.\App\Support\HospitalSettings::money($provider->float_balance)">
            <input id="dep-amount" type="number" step="0.01" min="0.01" wire:model="amount" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Bank reference" for="dep-ref" name="reference"
                      hint="Their reference, not ours — it is what a query about this payment will quote.">
            <input id="dep-ref" type="text" wire:model="reference" class="tb-input" maxlength="255">
          </x-ui.field>
          <x-ui.field label="Note" for="dep-note" name="notes">
            <input id="dep-note" type="text" wire:model="notes" class="tb-input" maxlength="255">
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveDeposit">
            <span wire:loading.remove wire:target="saveDeposit"><i class="fas fa-check" aria-hidden="true"></i> Record deposit</span>
            <span wire:loading wire:target="saveDeposit"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Recording…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Correct the float ───────────────────────────────────────────── --}}
  <x-ui.modal size="md" show="showAdjust" title="Correct the float">
    @if($showAdjust)
      <form wire:submit="saveAdjust" style="display:contents;">
        <div class="tb-modal-body">
          <p class="muted tb-mb-4">
            A ledger line is never edited or deleted. A correction is another line
            beside it, saying what was wrong and who said so.
          </p>

          <x-ui.field label="Direction" for="adj-dir" name="direction" required>
            <select id="adj-dir" wire:model="direction" class="tb-select">
              <option value="remove">Take off the float</option>
              <option value="add">Put onto the float</option>
            </select>
          </x-ui.field>

          <x-ui.field label="Amount" for="adj-amount" name="adjustAmount" required>
            <input id="adj-amount" type="number" step="0.01" min="0.01" wire:model="adjustAmount" class="tb-input" required>
          </x-ui.field>

          <x-ui.field label="Why" for="adj-why" name="reason" required
                      hint="An audit will read this sentence and nothing else.">
            <input id="adj-why" type="text" wire:model="reason" class="tb-input" maxlength="255" required>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveAdjust">
            <span wire:loading.remove wire:target="saveAdjust"><i class="fas fa-check" aria-hidden="true"></i> Post correction</span>
            <span wire:loading wire:target="saveAdjust"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Posting…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
