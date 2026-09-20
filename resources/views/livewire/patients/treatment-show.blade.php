<div>
  <x-ui.page-header :title="$record->procedure"
    :crumbs="['Patients' => route('admin.patients.index'), $patient->full_name => route('admin.patients.show', $patient), 'Treatment' => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <span class="muted tb-small">{{ ($record->performed_at ?? $record->created_at)->format('d M Y H:i') }}</span>
        @if($record->performedBy)<span class="muted tb-small">{{ $record->performedBy->name }}</span>@endif
        <x-ui.link :href="route('admin.patients.show', $patient)" class="tb-small">{{ $patient->full_name }}</x-ui.link>
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      <x-ui.link :href="route('admin.patients.show', $patient)" class="btn-tb">
        <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to patient
      </x-ui.link>
      @can('delete', $record)
        <button type="button" class="btn-tb btn-tb-danger" wire:click="delete"
                wire:loading.attr="disabled" wire:target="delete"
                wire:confirm="Remove this treatment record? Its photos are deleted too.">
          <span wire:loading.remove wire:target="delete"><i class="fas fa-trash" aria-hidden="true"></i> Delete record</span>
          <span wire:loading wire:target="delete"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Deleting…</span>
        </button>
      @endcan
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Notes</span></div>
    <div class="tb-card-body">
      @if($record->description)
        <p>{{ $record->description }}</p>
      @else
        <p class="muted">No notes were recorded.</p>
      @endif
    </div>
  </div>

  @if(filled($record->meta))
    <div class="tb-card tb-mt-4">
      <div class="tb-card-header"><span class="tb-card-title">Specialty details</span></div>
      <div class="tb-card-body">
        <dl>
          @foreach($record->meta as $key => $value)
            <div class="tb-mt-2" wire:key="meta-{{ $loop->index }}">
              <dt class="tb-label">{{ \Illuminate\Support\Str::headline((string) $key) }}</dt>
              <dd>{{ \App\Livewire\Patients\TreatmentShow::metaValue($value) }}</dd>
            </div>
          @endforeach
        </dl>
      </div>
    </div>
  @endif

  @if($record->photos->isNotEmpty())
    <div class="tb-card tb-mt-4">
      <div class="tb-card-header">
        <span class="tb-card-title">Photos</span>
        <span class="muted tb-xs">{{ $record->photos->count() }}</span>
      </div>
      <div class="tb-card-body">
        <ul class="tb-flex">
          @foreach($record->photos as $photo)
            <li wire:key="photo-{{ $photo->id }}">
              {{-- Private-disk stream: a real request on purpose (house rule 1). --}}
              <a href="{{ route('admin.patients.treatments.photo', [$patient, $photo]) }}" target="_blank" rel="noopener">
                <img src="{{ route('admin.patients.treatments.photo', [$patient, $photo]) }}"
                     alt="Treatment photo {{ $loop->iteration }} of {{ $record->photos->count() }}"
                     width="200" height="140" loading="lazy">
                <span class="sr-only">(opens in a new tab)</span>
              </a>
            </li>
          @endforeach
        </ul>
      </div>
    </div>
  @endif
</div>
