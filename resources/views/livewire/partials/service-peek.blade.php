{{-- One service, over the price list.

     A price list says what a thing costs and nothing about whether anybody is
     buying it. Two numbers — how often this quarter, and what that came to —
     are the difference between pruning a catalogue and guessing at it. --}}
<x-ui.modal show="showPeek" size="md" autosaves :title="$this->peeked?->name ?? 'Service'">
  @if($this->peeked)
    @php($svc = $this->peeked)
    @php($use = $this->peekedUsage)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$svc->name" :sub="$svc->code ?: 'No code'">
        <x-ui.badge :tone="$svc->is_active ? 'active' : 'danger'">{{ $svc->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
        @if($svc->tax_exempt)<x-ui.badge tone="neutral">Tax exempt</x-ui.badge>@endif
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Price', 'value' => \App\Support\HospitalSettings::money($svc->price)],
        ['label' => 'Ordered', 'value' => (string) $use['times'],
         'sub' => 'in the last '.\App\Livewire\Services\Index::USAGE_DAYS.' days'],
        ['label' => 'Which came to', 'value' => \App\Support\HospitalSettings::money($use['earned'])],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Code</dt><dd class="mono">{{ $svc->code ?: '—' }}</dd>
        <dt>Tax</dt><dd>{{ $svc->tax_exempt ? 'Exempt — no tax is added to it' : 'Taxable at the hospital rate' }}</dd>
        <dt>Orderable</dt>
        <dd>
          @if($svc->is_active)
            Yes — it appears when somebody adds work to a visit
          @else
            <span class="tb-peek-bad">No — it is archived and cannot be ordered</span>
          @endif
        </dd>
        <dt>Added</dt><dd>{{ $svc->created_at?->format('j M Y') ?? '—' }}</dd>
      </dl>

      @if($use['times'] === 0 && $svc->is_active)
        <p class="tb-out-none">
          Nothing has been ordered against this service in the last
          {{ \App\Livewire\Services\Index::USAGE_DAYS }} days.
        </p>
      @endif
    </div>

    <x-ui.peek-foot>
      @can('update', $svc)
        <button type="button" class="btn-tb btn-tb-primary" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit service
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
