<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-prescription-bottle-medical" aria-hidden="true"></i> Dosage</span>
    @can('prescribe', \App\Models\Prescription::class)
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openForm">
        <i class="fas fa-plus" aria-hidden="true"></i> Add dosage
      </button>
    @endcan
  </div>

  @if($this->doses->isEmpty())
    <div class="tb-card-body">
      <x-ui.empty compact icon="fa-prescription-bottle-medical" message="No medicine written on this visit." />
    </div>
  @else
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">How the patient takes each medicine</caption>
        <thead>
          <tr>
            <th>#</th><th>Medicine</th><th>Dose</th><th>How often</th><th>Days</th><th>Note</th>
            @can('prescribe', \App\Models\Prescription::class)<th><span class="sr-only">Actions</span></th>@endcan
          </tr>
        </thead>
        <tbody>
          @foreach($this->doses as $dose)
            <tr wire:key="dose-{{ $dose->id }}">
              <td class="muted">{{ $loop->iteration }}</td>
              <td><strong>{{ $dose->drug_name }}</strong></td>
              <td>{{ $dose->dosage ?: '' }}</td>
              <td>{{ $dose->howOften() }}</td>
              <td class="tb-nowrap">{{ $dose->days }}</td>
              <td class="muted">{{ $dose->instructions }}</td>
              @can('prescribe', \App\Models\Prescription::class)
                <td class="tb-text-right">
                  <x-ui.icon-button label="Remove {{ $dose->drug_name }}" icon="fa-trash" variant="danger"
                                    wire:click="remove({{ $dose->id }})"
                                    wire:confirm="Remove {{ $dose->drug_name }} from this visit?"
                                    wire:loading.attr="disabled" wire:target="remove({{ $dose->id }})" />
                </td>
              @endcan
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  <x-ui.modal size="xl" show="showForm" title="Add dosage">
    @if($showForm)
      <form wire:submit="save" class="dose-form">
        <div class="tb-modal-body">
          @include('livewire.partials.dosage-rows', ['keep' => 1])
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeForm">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save dosage</span>
            <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
