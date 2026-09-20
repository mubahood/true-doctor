@props([
  'show' => 'showSamples',
  'open' => false,   // the current boolean value of the $show property
  'title' => 'Import starter data',
  'noun' => 'items',
  'samples' => [],
  'columns' => [],   // [['key','label','edit'=>bool,'type'=>'text|number','align'=>'right'], ...]
  'importLabel' => 'Import selected',
])
{{--
  Reusable "import starter data" slide-over. The host Livewire component provides
  the $samples array (each row has name, selected + module fields) and the
  importSamples()/toggleAllSamples() methods from the ImportsSamples trait.
  wire: bindings resolve against the host component regardless of nesting.
--}}
<x-ui.modal size="xl" :show="$show" :title="$title">
  @if($open)
  <div class="tb-modal-body">
    <div class="smpl-tools">
      <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="toggleAllSamples(true)">Select all</button>
      <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="toggleAllSamples(false)">Clear</button>
      <span class="muted" style="margin-left:auto;font-size:.76rem;">Tick rows, adjust values, then import. Existing items are skipped.</span>
    </div>

    <div class="tb-table-wrap">
      <table class="tb-table smpl-table">
        <thead>
          <tr>
            <th style="width:36px;"></th>
            <th>Name</th>
            @foreach($columns as $c)
              <th style="{{ ($c['align'] ?? '') === 'right' ? 'text-align:right;' : '' }}">{{ $c['label'] }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @forelse($samples as $i => $s)
            <tr wire:key="smpl-{{ $i }}">
              <td><input type="checkbox" wire:model.live="samples.{{ $i }}.selected"></td>
              <td style="font-weight:500;">{{ $s['name'] }}</td>
              @foreach($columns as $c)
                <td style="{{ ($c['align'] ?? '') === 'right' ? 'text-align:right;' : '' }}">
                  @if($c['edit'] ?? false)
                    <input type="{{ $c['type'] ?? 'text' }}" @if(($c['type'] ?? '') === 'number') step="any" min="0" @endif
                           wire:model="samples.{{ $i }}.{{ $c['key'] }}" class="tb-input smpl-input">
                  @else
                    <span class="muted">{{ $s[$c['key']] ?? '—' }}</span>
                  @endif
                </td>
              @endforeach
            </tr>
          @empty
            <tr><td colspan="{{ 2 + count($columns) }}">
              <div class="tb-empty" style="padding:24px;"><i class="fas fa-circle-check"></i><p>All {{ $noun }} are already added — nothing to import.</p></div>
            </td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
  <div class="tb-modal-foot">
    <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
    @if(count($samples))
      <button type="button" class="btn-tb btn-tb-primary" wire:click="importSamples" wire:loading.attr="disabled" wire:target="importSamples">
        <span wire:loading.remove wire:target="importSamples"><i class="fas fa-download"></i> {{ $importLabel }}</span>
        <span wire:loading wire:target="importSamples"><i class="fas fa-spinner fa-spin"></i> Importing…</span>
      </button>
    @endif
  </div>
  @endif
</x-ui.modal>
