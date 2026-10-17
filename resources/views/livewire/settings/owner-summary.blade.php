<form wire:submit="save" class="tb-card tb-mb-4">
  <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-sun" aria-hidden="true"></i> My daily summary</span></div>
  <div class="tb-card-body">
    <label class="tb-check-group tb-mb-3">
      <input type="checkbox" wire:model.live="enabled">
      <span><b>Send me a summary of each day</b>: patients seen, money in, what is unpaid, stock alerts and what is worth a look</span>
    </label>

    <div class="tb-form-grid">
      <x-ui.field label="Send it by" for="os-channel" name="channel">
        <select id="os-channel" wire:model.live="channel" class="tb-select" @disabled(! $enabled)>
          @foreach($channels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
      </x-ui.field>
      <x-ui.field label="At" for="os-at" name="at" hint="It covers the day before. Never sent in the quiet hours above; it waits until they end.">
        <input id="os-at" type="time" wire:model="at" class="tb-input" @disabled(! $enabled)>
      </x-ui.field>
    </div>

    <div class="tb-mt-3">
      <div class="os-label">What it would say today</div>
      <p class="msg-preview os-preview-body">{{ $preview }}</p>
    </div>

    @if($recent->isNotEmpty())
      <div class="os-label tb-mt-3">The last few days</div>
      <ul class="os-recent">
        @foreach($recent as $row)
          <li wire:key="os-{{ $row->id }}">
            <span class="tb-nowrap">{{ $row->summary_date->format('D j M') }}</span>
            <x-ui.badge :tone="$row->status === 'sent' ? 'success' : ($row->status === 'failed' ? 'danger' : 'neutral')">{{ ucfirst($row->status) }}</x-ui.badge>
            <span class="muted tb-xs">{{ $channels[$row->channel] ?? $row->channel }}{{ $row->error ? ': '.$row->error : '' }}</span>
          </li>
        @endforeach
      </ul>
    @endif
  </div>
  <div class="tb-card-footer">
    <button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="save">Save</button>
  </div>
</form>
