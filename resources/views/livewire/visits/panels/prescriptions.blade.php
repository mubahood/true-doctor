<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-prescription" aria-hidden="true"></i> Prescriptions</span>
    @can('prescribe', \App\Models\Prescription::class)
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openForm">
        <i class="fas fa-plus" aria-hidden="true"></i> Write prescription
      </button>
    @endcan
  </div>
  <div class="tb-card-body">

    @forelse($this->prescriptions as $prescription)
      <div class="tb-panel" wire:key="rx-{{ $prescription->id }}">
        <div class="muted tb-xs">
          {{ $prescription->created_at->format('d M Y H:i') }}@if($prescription->prescriber) · {{ $prescription->prescriber->name }}@endif
        </div>

        @foreach($prescription->doseItems as $item)
          <div class="tb-mt-3" wire:key="rx-item-{{ $item->id }}">
            <strong>{{ $item->drug_name }}</strong> {{ $item->dosage }}
            <span class="muted tb-small">
              · {{ implode('/', array_map('ucfirst', $item->slots)) }} · {{ $item->days }} days
              @if($item->instructions) · {{ $item->instructions }} @endif
            </span>

            <div class="tb-table-wrap tb-mt-2">
              <table class="tb-table">
                <caption class="sr-only">Administration schedule for {{ $item->drug_name }}</caption>
                <thead>
                  <tr>
                    <th>Date</th><th>Slot</th><th>Status</th>
                    @can('administer', \App\Models\Prescription::class)<th><span class="sr-only">Actions</span></th>@endcan
                  </tr>
                </thead>
                <tbody>
                  @foreach($item->records as $record)
                    <tr wire:key="dose-{{ $record->id }}">
                      <td class="tb-nowrap">{{ $record->scheduled_date->format('d M') }}</td>
                      <td>{{ $record->slot->label() }}</td>
                      <td>
                        <x-ui.badge :tone="$record->status->badge()">{{ $record->status->label() }}</x-ui.badge>
                        @if($record->administeredBy)<span class="muted tb-xs">· {{ $record->administeredBy->name }}</span>@endif
                      </td>
                      @can('administer', \App\Models\Prescription::class)
                        <td class="tb-text-right tb-nowrap">
                          @if($record->status === \App\Enums\DoseRecordStatus::Pending)
                            <button type="button" class="btn-tb btn-tb-sm"
                                    wire:click="markDose({{ $record->id }}, 'administered')"
                                    wire:loading.attr="disabled" wire:target="markDose({{ $record->id }}, 'administered')">Given</button>
                            <button type="button" class="btn-tb btn-tb-sm btn-tb-danger"
                                    wire:click="markDose({{ $record->id }}, 'missed')"
                                    wire:confirm="Mark this dose as missed?"
                                    wire:loading.attr="disabled" wire:target="markDose({{ $record->id }}, 'missed')">Missed</button>
                          @endif
                        </td>
                      @endcan
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        @endforeach
      </div>
    @empty
      <x-ui.empty compact icon="fa-prescription" message="Nothing prescribed on this visit." />
    @endforelse
  </div>

  {{-- ── Writing one ─────────────────────────────────────────────── --}}
  <x-ui.modal size="xl" show="showForm" title="Write a prescription">
    @if($showForm)
        <form wire:submit="prescribe" style="display:contents;">
        <div class="tb-modal-body">
          @error('items')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror

          @foreach($items as $index => $row)
            <div class="tb-mb-4" wire:key="rx-row-{{ $row['uid'] }}">
              <div class="tb-grid-2">
                <x-ui.field label="Drug" :for="'rx-drug-'.$index" :name="'items.'.$index.'.drug_name'" required>
                  <input id="rx-drug-{{ $index }}" type="text" class="tb-input" maxlength="120"
                         wire:model="items.{{ $index }}.drug_name" required>
                </x-ui.field>
                <x-ui.field label="Dosage" :for="'rx-dose-'.$index" :name="'items.'.$index.'.dosage'">
                  <input id="rx-dose-{{ $index }}" type="text" class="tb-input" maxlength="60" placeholder="500mg"
                         wire:model="items.{{ $index }}.dosage">
                </x-ui.field>
              </div>

              <fieldset>
                <legend class="tb-label">Daily slots</legend>
                <div class="tb-flex">
                  @foreach($this->slots as $value => $label)
                    <label class="tb-flex tb-small" wire:key="rx-slot-{{ $row['uid'] }}-{{ $value }}">
                      <input type="checkbox" value="{{ $value }}" wire:model="items.{{ $index }}.slots"> {{ $label }}
                    </label>
                  @endforeach
                </div>
                @error('items.'.$index.'.slots')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
              </fieldset>

              <div class="tb-inline-form tb-mt-3">
                <x-ui.field label="Days" :for="'rx-days-'.$index" :name="'items.'.$index.'.days'" required>
                  <x-ui.suggestions :set="'items.'.$index.'.days'" :current="$item['days'] ?? null"
                                    :options="['3' => '3 days', '5' => '5 days', '7' => '1 week', '14' => '2 weeks', '30' => '1 month']" />
                  <input id="rx-days-{{ $index }}" type="number" min="1" max="90" class="tb-input"
                         wire:model="items.{{ $index }}.days" required>
                </x-ui.field>
                <x-ui.field label="Start" :for="'rx-start-'.$index" :name="'items.'.$index.'.start_date'">
                  <input id="rx-start-{{ $index }}" type="date" class="tb-input"
                         wire:model="items.{{ $index }}.start_date">
                </x-ui.field>
                <x-ui.field label="Instructions" :for="'rx-instr-'.$index" :name="'items.'.$index.'.instructions'">
                  <input id="rx-instr-{{ $index }}" type="text" class="tb-input" maxlength="255" placeholder="after meals"
                         wire:model="items.{{ $index }}.instructions">
                </x-ui.field>
                @if(count($items) > 1)
                  <x-ui.icon-button label="Remove drug {{ $index + 1 }}" icon="fa-trash" variant="danger"
                                    wire:click="removeRow({{ $index }})" />
                @endif
              </div>
            </div>
          @endforeach

          <x-ui.field label="Notes" for="rx-notes" name="notes">
            <input id="rx-notes" type="text" class="tb-input" maxlength="255" wire:model="notes">
          </x-ui.field>

          <button type="button" class="btn-tb btn-tb-sm" wire:click="addRow">
            <i class="fas fa-plus" aria-hidden="true"></i> Add another drug
          </button>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeForm">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary"
                  wire:loading.attr="disabled" wire:target="prescribe">
            <span wire:loading.remove wire:target="prescribe"><i class="fas fa-check" aria-hidden="true"></i> Add prescription</span>
            <span wire:loading wire:target="prescribe"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
