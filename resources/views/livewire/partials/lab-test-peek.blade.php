{{-- One lab test, over the catalogue.

     The reference range is truncated in the row, and a range is the one field
     a bench cannot work from half of. It is carried whole here, with what the
     test has actually been ordered. --}}
<x-ui.modal show="showPeek" size="md" autosaves :title="$this->peeked?->name ?? 'Lab test'">
  @if($this->peeked)
    @php($test = $this->peeked)
    @php($use = $this->peekedUsage)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$test->name" :sub="$test->code ?: 'No code'">
        <x-ui.badge :tone="$test->is_active ? 'success' : 'danger'">{{ $test->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Price', 'value' => \App\Support\HospitalSettings::money($test->price)],
        ['label' => 'Ordered', 'value' => (string) $use['times'],
         'sub' => 'in the last '.\App\Livewire\LabTests\Index::USAGE_DAYS.' days'],
        ['label' => 'Which came to', 'value' => \App\Support\HospitalSettings::money($use['earned'])],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Specimen</dt><dd>{{ $test->specimen ?: '—' }}</dd>
        <dt>Reported in</dt><dd class="mono">{{ $test->unit ?: '—' }}</dd>
        <dt>Normal range</dt>
        <dd>
          @if($test->reference_range)
            <span class="mono">{{ $test->reference_range }}</span> {{ $test->unit }}
          @else
            <span class="tb-peek-bad">None set — a result has nothing to be flagged against</span>
          @endif
        </dd>
        <dt>Orderable</dt>
        <dd>
          @if($test->is_active)
            Yes — it appears when a doctor raises a lab order
          @else
            <span class="tb-peek-bad">No — it is archived</span>
          @endif
        </dd>
      </dl>
    </div>

    <x-ui.peek-foot :href="route('admin.lab-orders.index')" label="Lab orders" icon="fa-flask">
      @can('update', $test)
        <button type="button" class="btn-tb btn-tb-primary" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit test
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
