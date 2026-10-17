<div>
  <h1 class="sr-only">Message credit</h1>

  @unless($available)
    <div class="fn-note is-warn tb-mb-3"><i class="fas fa-circle-info" aria-hidden="true"></i>
      This server sends text messages to the log only (SMS_DRIVER is not a live gateway), so no hospital can switch messages on yet.</div>
  @endunless

  <form wire:submit="topUp" class="tb-card tb-mb-4">
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-coins" aria-hidden="true"></i> Add messages to a hospital</span></div>
    <div class="tb-card-body">
      <div class="tb-form-grid">
        <x-ui.field label="Hospital" for="mc-hospital" name="hospitalId" required>
          <select id="mc-hospital" wire:model="hospitalId" class="tb-select">
            <option value="">Choose…</option>
            @forelse($rows as $r)<option value="{{ $r['hospital']->id }}">{{ $r['hospital']->name }}</option>@endforeach
          </select>
        </x-ui.field>
        <x-ui.field label="Messages" for="mc-messages" name="quantity" required hint="One message is one text of 160 letters.">
          <input id="mc-messages" type="number" min="1" wire:model="quantity" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Payment reference" for="mc-ref" name="reference" required hint="The mobile money or bank reference it was paid with.">
          <input id="mc-ref" type="text" wire:model="reference" class="tb-input" maxlength="100">
        </x-ui.field>
        <x-ui.field label="Note" for="mc-note" name="note">
          <input id="mc-note" type="text" wire:model="note" class="tb-input" maxlength="255">
        </x-ui.field>
      </div>
    </div>
    <div class="tb-card-footer"><button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="topUp">Add messages</button></div>
  </form>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Hospital name…" aria-label="Search hospitals">
    </div>
  </div>
  <div class="tb-card tb-mb-4"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Hospital</th><th>Messages</th><th class="tb-text-right">Left</th><th class="tb-text-right">Sent this month</th></tr></thead>
    <tbody>
      @foreach($rows as $r)
        <tr wire:key="h-{{ $r['hospital']->id }}">
          <td class="tb-fw-500">{{ $r['hospital']->name }}</td>
          <td><x-ui.badge :tone="$r['on'] ? 'success' : 'neutral'">{{ $r['on'] ? 'On' : 'Off' }}</x-ui.badge></td>
          <td class="tb-text-right mono">{{ number_format($r['balance']) }}</td>
          <td class="tb-text-right mono">{{ number_format($r['sent']) }}</td>
        </tr>
      @empty
        <tr><td colspan="4"><x-ui.empty icon="fa-hospital" noun="hospitals" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div>
  {{ $hospitals->links(data: $this->paginationData()) }}
  </div>

  <div class="tb-card"><div class="tb-card-header"><span class="tb-card-title">Latest top-ups</span></div>
    <div class="tb-table-wrap"><table class="tb-table">
      <thead><tr><th>When</th><th>Hospital</th><th class="tb-text-right">Messages</th><th>Reference</th></tr></thead>
      <tbody>
        @forelse($recent as $c)
          <tr wire:key="c-{{ $c->id }}">
            <td class="tb-small tb-nowrap">{{ $c->created_at?->format('d M Y H:i') }}</td>
            <td class="tb-small">{{ $names[$c->hospital_id] ?? '#'.$c->hospital_id }}</td>
            <td class="tb-text-right mono">+{{ number_format($c->messages) }}</td>
            <td class="tb-small mono">{{ $c->reference }}</td>
          </tr>
        @empty
          <tr><td colspan="4"><x-ui.empty compact icon="fa-coins" message="No top-ups yet." /></td></tr>
        @endforelse
      </tbody>
    </table></div>
  </div>
</div>
