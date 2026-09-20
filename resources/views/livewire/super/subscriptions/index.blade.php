<div>
  <h1 class="sr-only">Subscriptions</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search hospital…" aria-label="Search subscriptions">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Subscription::class)<button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New subscription</button>@endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Hospital</th><th>Plan</th><th>Status</th><th>Starts</th><th>Ends</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $s)
        <tr wire:key="sub-{{ $s->id }}">
          <td class="tb-fw-500">{{ $s->hospital?->name ?? '—' }}</td>
          <td class="muted">{{ $s->plan?->name ?? '—' }}</td>
          <td><x-ui.badge :tone="$s->status->value === 'active' ? 'active' : ($s->status->value === 'trialing' ? 'info' : 'neutral')">{{ $s->status->label() }}</x-ui.badge></td>
          <td class="muted">{{ $s->starts_at?->format('d M Y') ?? '—' }}</td>
          <td class="muted">{{ $s->ends_at?->format('d M Y') ?? '—' }}</td>
          <td class="tb-text-right tb-nowrap">
            @can('update', $s)
              <x-ui.icon-button label="Record payment for {{ $s->hospital?->name }}" icon="fa-money-bill-wave" wire:click="recordPayment({{ $s->id }})" />
              <x-ui.icon-button label="Edit subscription for {{ $s->hospital?->name }}" icon="fa-pen" wire:click="edit({{ $s->id }})" />
            @endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty icon="fa-file-invoice-dollar" noun="subscriptions" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal size="xl" show="showForm" :title="$editingId ? 'Edit subscription' : 'New subscription'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Hospital" for="sub-hospital" name="hospital_id" required>
          <select id="sub-hospital" wire:model="hospital_id" class="tb-select">
            <option value="">— select —</option>
            @foreach($this->hospitals as $hh)<option value="{{ $hh->id }}">{{ $hh->name }}</option>@endforeach
          </select>
        </x-ui.field>
        <div class="tb-form-grid">
          <x-ui.field label="Plan" for="sub-plan" name="plan_id" required>
            <select id="sub-plan" wire:model="plan_id" class="tb-select">
              <option value="">— select —</option>
              @foreach($this->plans as $pl)<option value="{{ $pl->id }}">{{ $pl->name }}</option>@endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="Status" for="sub-status" name="status" required>
            <select id="sub-status" wire:model="status" class="tb-select">
              @foreach($this->statuses as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="Starts on" for="sub-starts" name="starts_at" required>
            <input id="sub-starts" type="date" wire:model="starts_at" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Ends on" for="sub-ends" name="ends_at">
            <input id="sub-ends" type="date" wire:model="ends_at" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Trial ends on" for="sub-trial" name="trial_ends_at">
            <input id="sub-trial" type="date" wire:model="trial_ends_at" class="tb-input">
          </x-ui.field>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create subscription' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  {{-- ── Slide-over: record a manual payment ───────────────────── --}}
  <x-ui.modal size="xl" show="showPayment" title="Record payment">
    @if($showPayment)
    <form wire:submit="savePayment" style="display:contents;">
      <div class="tb-modal-body">
        <div class="tb-form-grid">
          <x-ui.field label="Amount" for="pay-amount" name="amount" required>
            <input id="pay-amount" type="number" step="any" min="0.01" wire:model="amount" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Method" for="pay-method" name="method" required>
            <input id="pay-method" type="text" wire:model="method" class="tb-input" placeholder="manual" required>
          </x-ui.field>
          <x-ui.field label="Paid on" for="pay-date" name="paid_at" required>
            <input id="pay-date" type="date" wire:model="paid_at" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Extend by (days)" for="pay-extend" name="extend_days" required hint="0 records the payment without moving the end date.">
            <input id="pay-extend" type="number" min="0" max="730" wire:model="extend_days" class="tb-input" required>
          </x-ui.field>
        </div>
        <x-ui.field label="Reference" for="pay-ref" name="reference">
          <input id="pay-ref" type="text" wire:model="reference" class="tb-input" placeholder="Mobile-money / bank reference">
        </x-ui.field>
        <x-ui.field label="Notes" for="pay-notes" name="notes">
          <textarea id="pay-notes" wire:model="notes" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="savePayment">
          <span wire:loading.remove wire:target="savePayment"><i class="fas fa-check" aria-hidden="true"></i> Record payment</span>
          <span wire:loading wire:target="savePayment"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>
</div>
