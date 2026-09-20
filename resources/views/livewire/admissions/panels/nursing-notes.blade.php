<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-user-nurse" aria-hidden="true"></i> Nursing notes ({{ $this->notes->count() }})</span>
  </div>

  @can('manage', \App\Models\Admission::class)
    @if($this->admission->status->isActive())
      <div class="tb-card-body">
        <form wire:submit="add">
          <x-ui.field label="New note" for="nn-note" name="note" required>
            <textarea id="nn-note" class="tb-input" rows="3" wire:model="note" maxlength="5000" required></textarea>
          </x-ui.field>
          <div class="tb-flex tb-flex-end tb-mt-3">
            <button type="submit" class="btn-tb btn-tb-sm btn-tb-primary" wire:loading.attr="disabled" wire:target="add">
              <span wire:loading.remove wire:target="add"><i class="fas fa-plus" aria-hidden="true"></i> Add note</span>
              <span wire:loading wire:target="add"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
            </button>
          </div>
        </form>
      </div>
    @endif
  @endcan

  <div class="tb-card-body">
    <div class="tb-timeline">
      @forelse($this->notes as $n)
        <div class="tb-tl-item" wire:key="note-{{ $n->id }}">
          <div class="m">{{ $n->created_at?->format('d M Y H:i') }}@if($n->recordedBy) · {{ $n->recordedBy->name }}@endif</div>
          <div class="t">{{ $n->note }}</div>
        </div>
      @empty
        <x-ui.empty icon="fa-user-nurse" noun="nursing notes" />
      @endforelse
    </div>
  </div>
</div>
