{{-- One imaging study, over the catalogue. --}}
<x-ui.modal show="showPeek" size="md" autosaves :title="$this->peeked?->name ?? 'Study'">
  @if($this->peeked)
    @php($study = $this->peeked)
    @php($use = $this->peekedUsage)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$study->name"
                      :sub="trim(($study->modality ?: 'No modality').' · '.($study->body_part ?: 'no body part'))">
        <x-ui.badge :tone="$study->is_active ? 'success' : 'danger'">{{ $study->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Price', 'value' => \App\Support\HospitalSettings::money($study->price)],
        ['label' => 'Ordered', 'value' => (string) $use['times'],
         'sub' => 'in the last '.\App\Livewire\RadiologyStudies\Index::USAGE_DAYS.' days'],
        ['label' => 'Which came to', 'value' => \App\Support\HospitalSettings::money($use['earned'])],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Modality</dt><dd>{{ $study->modality ?: '—' }}</dd>
        <dt>Body part</dt><dd>{{ $study->body_part ?: '—' }}</dd>
        <dt>Orderable</dt>
        <dd>
          @if($study->is_active)
            Yes — it appears when a doctor raises an imaging order
          @else
            <span class="tb-peek-bad">No — it is archived</span>
          @endif
        </dd>
        <dt>Added</dt><dd>{{ $study->created_at?->format('j M Y') ?? '—' }}</dd>
      </dl>
    </div>

    <x-ui.peek-foot :href="route('admin.radiology-orders.index')" label="Imaging orders" icon="fa-x-ray">
      @can('update', $study)
        <button type="button" class="btn-tb btn-tb-primary" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit study
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
