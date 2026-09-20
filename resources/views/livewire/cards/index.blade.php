<div>
  <h1 class="sr-only">Cards</h1>

  {{-- What the FILTERED list adds up to, which is the point of filtering it.
       A card desk works to two figures: what the hospital is holding on behalf
       of patients, and what it is owed. --}}
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-credit-card" :value="$this->totals['cards']" label="Cards" />
    <x-dash.stat icon="fa-piggy-bank" tone="ok"
                 :value="\App\Support\HospitalSettings::money($this->totals['held'])" label="Held on card" />
    <x-dash.stat icon="fa-hand-holding-dollar"
                 :tone="bccomp($this->totals['owed'], '0', 2) > 0 ? 'bad' : ''"
                 :value="\App\Support\HospitalSettings::money($this->totals['owed'])" label="Owed to the hospital" />
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap" style="position:relative;">
      <i class="fas fa-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--mt2);font-size:12px;"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
             placeholder="Patient, patient no. or member no.…" style="padding-left:32px;min-width:240px;">
    </div>

    <select wire:model.live="status" class="tb-select">
      <option value="">Any status</option>
      @foreach($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>

    <select wire:model.live="insurer" class="tb-select">
      <option value="">Any card</option>
      <option value="own">The patient’s own</option>
      <option value="insured">Any insurer</option>
      @foreach($this->insurers as $insurer)
        <option value="{{ $insurer->id }}">{{ $insurer->name }}</option>
      @endforeach
    </select>

    <label class="tb-check-group">
      <input type="checkbox" wire:model.live="owing"> In debt only
    </label>

    <div wire:loading.flex wire:target="search,status,insurer,owing" class="muted"
         style="font-size:.8rem;align-items:center;gap:6px;">
      <i class="fas fa-circle-notch fa-spin"></i> Loading…
    </div>
  </div>

  <div class="tb-card" wire:loading.class="tb-loading" wire:target="search,status,insurer,owing">
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Every card in the hospital</caption>
        <thead>
          <tr>
            <th class="tb-serial">#</th><th>Card</th>
            <th>Held by</th>
            <th>Insurer</th>
            <th>Status</th>
            <x-ui.th-sort field="balance" :sort-field="$sortField" :sort-dir="$sortDir" align="right">Balance</x-ui.th-sort>
            <th style="text-align:right;">Credit limit</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $card)
            <tr wire:key="card-{{ $card->id }}">
              <x-ui.serial :rows="$rows" :loop="$loop" />
              <td class="mono">
                {{-- Opens the card over the list, like everywhere else. --}}
                <button type="button" class="tb-rowbtn" wire:click="peek({{ $card->id }})"
                        title="See this card">{{ $card->masked() }}</button>
              </td>
              <td>
                <span style="font-weight:500;">{{ $card->patient?->full_name ?? '—' }}</span>
                @if($card->holders_count > 0)
                  <span class="muted" style="font-size:.78rem;">
                    + {{ $card->holders_count }} {{ Str::plural('other', $card->holders_count) }}
                  </span>
                @endif
              </td>
              <td>
                @if($card->provider)
                  <a wire:navigate href="{{ route('admin.insurance-providers.show', $card->insurance_provider_id) }}">{{ $card->provider->name }}</a>
                  @if($card->member_no)<span class="muted mono" style="font-size:.78rem;">{{ $card->member_no }}</span>@endif
                @else
                  <span class="muted">Their own</span>
                @endif
              </td>
              <td>
                <x-ui.badge :tone="$card->status->badge()">{{ $card->status->label() }}</x-ui.badge>
                @if($card->isExpired())<x-ui.badge tone="danger">Expired</x-ui.badge>@endif
              </td>
              <td style="text-align:right;" class="mono {{ bccomp((string) $card->balance, '0', 2) < 0 ? 'tb-danger' : '' }}">
                {{ \App\Support\HospitalSettings::money($card->balance) }}
              </td>
              <td style="text-align:right;" class="mono muted">
                @if($card->accepts_credit){{ \App\Support\HospitalSettings::money($card->max_credit) }}@else — @endif
              </td>
              <td style="text-align:right;" class="tb-nowrap">
                <button type="button" class="btn-tb btn-tb-ghost btn-tb-icon" wire:click="peek({{ $card->id }})"
                        aria-label="See card {{ $card->masked() }}"><i class="fas fa-eye" aria-hidden="true"></i></button>
                <x-ui.actions-menu label="Actions for card {{ $card->masked() }}">
                  <button type="button" role="menuitem" wire:click="peek({{ $card->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Quick view</button>
                  <a role="menuitem" wire:navigate href="{{ route('admin.cards.show', $card->uuid) }}"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record</a>
                  @if($card->patient)
                    <a role="menuitem" wire:navigate href="{{ route('admin.patients.show', $card->patient) }}"><i class="fas fa-user" aria-hidden="true"></i> The patient</a>
                  @endif
                </x-ui.actions-menu>
              </td>
            </tr>
          @empty
            <tr><td colspan="8"><x-ui.empty icon="fa-credit-card" noun="cards" /></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{ $rows->links(data: $this->paginationData()) }}

  @include('livewire.partials.card-peek')
</div>
