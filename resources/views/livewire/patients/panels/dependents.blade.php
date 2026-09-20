<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-people-roof" aria-hidden="true"></i> Dependents &amp; guardians</span>
    @can('manageDependents', $this->patient)
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openLink">
        <i class="fas fa-link" aria-hidden="true"></i> Link dependent
      </button>
    @endcan
  </div>

  <div class="tb-card-body">
    <div class="tb-label">Dependents of this patient</div>
  </div>
  <div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Dependents of this patient</caption>
      <tbody>
        @forelse($this->links as $link)
          <tr wire:key="dep-{{ $link->id }}">
            <td>
              @if($link->dependent)
                <x-ui.link :href="route('admin.patients.show', $link->dependent)">{{ $link->dependent->full_name }}</x-ui.link>
                <span class="muted mono">{{ $link->dependent->patient_no }}</span>
              @else
                <span class="muted">—</span>
              @endif
            </td>
            <td><x-ui.badge tone="info">{{ ucfirst($link->relationship) }}</x-ui.badge></td>
            <td class="tb-text-right">
              @can('manageDependents', $this->patient)
                <x-ui.icon-button label="Unlink {{ $link->dependent?->full_name ?? 'dependent' }}" icon="fa-link-slash" variant="danger"
                                  wire:click="unlink({{ $link->id }})" wire:confirm="Unlink this dependent?" />
              @endcan
            </td>
          </tr>
        @empty
          <tr><td colspan="3"><x-ui.empty icon="fa-people-roof" noun="dependents linked" /></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($this->guardians->isNotEmpty())
    <div class="tb-card-body">
      <div class="tb-label">This patient is a dependent of</div>
    </div>
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Guardians of this patient</caption>
        <tbody>
          @foreach($this->guardians as $guardian)
            <tr wire:key="guard-{{ $guardian->id }}">
              <td>
                @if($guardian->guardian)
                  <x-ui.link :href="route('admin.patients.show', $guardian->guardian)">{{ $guardian->guardian->full_name }}</x-ui.link>
                  <span class="muted mono">{{ $guardian->guardian->patient_no }}</span>
                @else
                  <span class="muted">—</span>
                @endif
              </td>
              <td><x-ui.badge tone="info">{{ ucfirst($guardian->relationship) }}</x-ui.badge></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  {{-- ── Slide-over: link a dependent (async patient search) ──── --}}
  <x-ui.modal size="md" show="showLink" title="Link a dependent">
    @if($showLink)
      <form wire:submit="link" style="display:contents;">
        <div class="tb-modal-body">
          @if($this->picked)
            <x-ui.field label="Dependent" for="dep-picked" name="dependent_uuid" required>
              <div class="tb-flex" id="dep-picked">
                <span class="tb-fw-500">{{ $this->picked->full_name }}</span>
                <span class="muted mono">{{ $this->picked->patient_no }}</span>
                <x-ui.icon-button label="Choose a different patient" icon="fa-xmark" wire:click="clearSelection" />
              </div>
            </x-ui.field>
          @else
            <x-ui.field label="Find the dependent" for="dep-search" name="dependent_uuid" required
                        hint="Search this hospital's patients by name, number or phone — at least 2 characters.">
              <input id="dep-search" type="search" class="tb-input" autocomplete="off"
                     wire:model.live.debounce.300ms="query" placeholder="e.g. Nakato or PT-2026-000123">
              <div wire:loading wire:target="query" class="muted tb-small tb-mt-2">
                <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Searching…
              </div>
            </x-ui.field>

            @if($this->results->isNotEmpty())
              <div class="tb-table-wrap">
                <table class="tb-table">
                  <caption class="sr-only">Search results</caption>
                  <tbody>
                    @foreach($this->results as $result)
                      <tr wire:key="hit-{{ $result->id }}">
                        <td>{{ $result->full_name }}</td>
                        <td class="muted mono">{{ $result->patient_no }}</td>
                        <td class="tb-text-right">
                          <button type="button" class="btn-tb btn-tb-sm" wire:click="select('{{ $result->uuid }}')">Select</button>
                        </td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            @elseif(strlen(trim($query)) >= 2)
              <p class="muted tb-small">No patients match “{{ $query }}”.</p>
            @endif
          @endif

          <x-ui.field label="Relationship" for="dep-rel" name="relationship" required>
            <select id="dep-rel" wire:model="relationship" class="tb-select" required>
              @foreach($this->relationships as $relationship)
                <option value="{{ $relationship }}">{{ ucfirst($relationship) }}</option>
              @endforeach
            </select>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="link">
            <span wire:loading.remove wire:target="link"><i class="fas fa-link" aria-hidden="true"></i> Link dependent</span>
            <span wire:loading wire:target="link"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Linking…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
