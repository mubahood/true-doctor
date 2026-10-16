@php
  $chart = $this->chart;
  $money = fn ($v) => \App\Support\HospitalSettings::money($v);
  $statusTone = fn ($s) => $s->tone();
  $quoteUrl = route('admin.patients.dental-quote', $this->patient);
@endphp
<div class="tb-stack dental">

  {{-- ── The chart ──────────────────────────────────────────── --}}
  <div class="tb-card">
    <div class="tb-card-header">
      <span class="tb-card-title"><i class="fas fa-tooth" aria-hidden="true"></i> Dental chart</span>
      <div class="tb-flex">
        <label class="tb-check-group tb-xs"><input type="checkbox" wire:model.live="primary"> Child's teeth</label>
        <label class="sr-only" for="dental-asof">Chart as it was on</label>
        <input id="dental-asof" type="date" class="tb-input tb-input-slim" wire:model.live="asOf" max="{{ now()->toDateString() }}" title="Show the chart as it was on a date">
        @if($asOf)<button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="$set('asOf', null)">Today</button>@endif
      </div>
    </div>
    <div class="tb-card-body">
      @if($asOf)<p class="muted tb-xs">The chart as it was on {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}.</p>@endif
      <div class="odonto" role="grid" aria-label="Teeth, FDI notation">
        @foreach($this->rows() as $r => [$right, $left])
          <div class="odonto-row {{ $r === 0 ? 'upper' : 'lower' }}" role="row">
            @foreach([$right, $left] as $side => $teeth)
              <div class="odonto-half {{ $side === 0 ? 'right' : 'left' }}">
                @foreach($teeth as $t)
                  @php($s = $chart[$t] ?? null)
                  <button type="button" role="gridcell" wire:click="pick({{ $t }})" wire:key="tooth-{{ $t }}"
                          @class(['tooth', 'on' => $tooth === $t, 'f-'.($s['finding'] ?? 'none'), 'has-surf' => ! empty($s['surfaces'])])
                          aria-pressed="{{ $tooth === $t ? 'true' : 'false' }}"
                          aria-label="Tooth {{ $t }}{{ $s && $s['label'] ? ': '.$s['label'] : '' }}{{ ! empty($s['surfaces']) ? ', surfaces '.implode(' ', array_map(fn ($k, $v) => $k.' '.str_replace('_', ' ', $v), array_keys($s['surfaces']), $s['surfaces'])) : '' }}">
                    <span class="tooth-n">{{ $t }}</span>
                    <span class="tooth-s" aria-hidden="true">
                      @foreach(\App\Services\DentalService::SURFACES as $sf)
                        <i class="s-{{ $sf }} {{ isset($s['surfaces'][$sf]) ? 'sf-'.$s['surfaces'][$sf] : '' }}"></i>
                      @endforeach
                    </span>
                    @if($s && $s['finding'])<span class="tooth-f">{{ $s['label'] }}</span>@endif
                  </button>
                @endforeach
              </div>
            @endforeach
          </div>
        @endforeach
      </div>
      <p class="odonto-key muted tb-xs">
        <span class="k f-caries"></span> Caries <span class="k f-filled"></span> Filled <span class="k f-missing"></span> Missing
        <span class="k f-to_extract"></span> To extract <span class="k f-crown"></span> Crown or implant
      </p>
    </div>
  </div>

  {{-- ── The chosen tooth ────────────────────────────────────── --}}
  @if($tooth)
    @php($s = $chart[$tooth] ?? null)
    <div class="tb-card">
      <div class="tb-card-header">
        <span class="tb-card-title">Tooth {{ $tooth }}</span>
        @if($s && $s['label'])<x-ui.badge :tone="$s['tone']">{{ $s['label'] }}@if($s['mobility'] !== null) {{ $s['mobility'] }}@endif</x-ui.badge>@endif
      </div>
      <div class="tb-card-body">
        @if($s && $s['surfaces'])
          <p class="tb-small">Surfaces:
            @foreach($s['surfaces'] as $sf => $f)<x-ui.badge :tone="\App\Enums\DentalFinding::from($f)->tone()">{{ $sf }} {{ \App\Enums\DentalFinding::from($f)->label() }}</x-ui.badge> @endforeach
          </p>
        @endif
        @if($s && $s['notes'])<p class="muted tb-xs">{{ implode(' · ', $s['notes']) }}</p>@endif

        @if($canChart && ! $asOf)
          <form wire:submit="record" class="dental-form">
            <fieldset class="dental-surfaces">
              <legend class="tb-label">Surfaces <span class="muted">(none for the whole tooth)</span></legend>
              @foreach(['M' => 'Mesial', 'O' => 'Occlusal', 'D' => 'Distal', 'B' => 'Buccal', 'L' => 'Lingual', 'I' => 'Incisal'] as $code => $name)
                <label class="tb-chip-check" title="{{ $name }}"><input type="checkbox" value="{{ $code }}" wire:model="surfaces"> {{ $code }}</label>
              @endforeach
            </fieldset>
            <div class="tb-form-grid">
              <x-ui.field label="Finding" for="dental-finding" name="finding">
                <select id="dental-finding" class="tb-select" wire:model.live="finding">
                  @foreach($findings as $f)<option value="{{ $f->value }}">{{ $f->label() }}</option>@endforeach
                </select>
              </x-ui.field>
              @if($finding === 'mobility')
                <x-ui.field label="Mobility grade" for="dental-mob" name="mobility">
                  <select id="dental-mob" class="tb-select" wire:model="mobility"><option value="">Choose…</option>@foreach([0, 1, 2, 3] as $g)<option value="{{ $g }}">{{ $g }}</option>@endforeach</select>
                </x-ui.field>
              @endif
              <x-ui.field label="Note" for="dental-note" name="notes">
                <input id="dental-note" type="text" class="tb-input" wire:model="notes" maxlength="500">
              </x-ui.field>
            </div>
            @error('tooth')<p class="tb-field-error">{{ $message }}</p>@enderror
            <div class="tb-flex">
              <button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="record"><i class="fas fa-check" aria-hidden="true"></i> Record</button>
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="openPlan({{ $tooth }})"><i class="fas fa-plus" aria-hidden="true"></i> Plan a procedure on {{ $tooth }}</button>
            </div>
          </form>
        @endif
      </div>
    </div>
  @endif

  {{-- ── Treatment plan ──────────────────────────────────────── --}}
  <div class="tb-card">
    <div class="tb-card-header">
      <span class="tb-card-title">Treatment plan</span>
      <div class="tb-flex">
        @if($this->plan->isNotEmpty())
          <a href="{{ $quoteUrl }}" target="_blank" rel="noopener" class="btn-tb btn-tb-ghost btn-tb-sm"><i class="fas fa-file-pdf" aria-hidden="true"></i> Quote</a>
        @endif
        @if($canChart)<button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="openPlan"><i class="fas fa-plus" aria-hidden="true"></i> Add</button>@endif
      </div>
    </div>
    @if($this->plan->isEmpty())
      <div class="tb-card-body"><x-ui.empty icon="fa-tooth" compact message="Nothing planned yet. Choose a tooth, then plan a procedure on it." /></div>
    @else
      <div class="tb-table-wrap"><table class="tb-table">
        <caption class="sr-only">Treatment plan</caption>
        <thead><tr><th>Procedure</th><th>Tooth</th><th class="tb-text-right">Price</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          @foreach($this->plan as $item)
            <tr wire:key="dti-{{ $item->uuid }}">
              <td><b class="tb-small">{{ $item->service?->name }}</b>@if($item->notes)<div class="muted tb-xs">{{ $item->notes }}</div>@endif</td>
              <td class="mono">{{ $item->tooth ?? '—' }}{{ $item->surfaces ? ' '.$item->surfaces : '' }}</td>
              <td class="tb-text-right mono">{{ $money($item->price) }}</td>
              <td><x-ui.badge :tone="$statusTone($item->status)">{{ $item->status->label() }}</x-ui.badge>
                @if($item->done_at)<div class="muted tb-xs">{{ $item->done_at->format('d M Y') }} · {{ $item->doneBy?->name }}</div>@endif
              </td>
              <td class="tb-text-right tb-nowrap">
                @if($canMove && $item->status->isOpen())
                  @foreach($item->status->transitionsTo() as $next)
                    @continue($next === \App\Enums\DentalTreatmentStatus::Cancelled)
                    <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="move('{{ $item->uuid }}', '{{ $next->value }}')"
                            @if($next === \App\Enums\DentalTreatmentStatus::Done) wire:confirm="Mark done and bill {{ $money($item->price) }} on the open visit?" @endif>{{ $next === \App\Enums\DentalTreatmentStatus::Done ? 'Done & bill' : ($next === \App\Enums\DentalTreatmentStatus::InProgress ? 'Start' : 'Accept') }}</button>
                  @endforeach
                  <x-ui.icon-button label="Cancel {{ $item->service?->name }}" icon="fa-xmark" wire:click="move('{{ $item->uuid }}', 'cancelled')" wire:confirm="Cancel this planned procedure?" />
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    @endif
  </div>

  {{-- ── Recall ──────────────────────────────────────────────── --}}
  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Next check-up</span></div>
    <div class="tb-card-body">
      <form wire:submit="saveRecall" class="tb-flex dental-recall">
        <label class="sr-only" for="dental-recall">Next check-up</label>
        <input id="dental-recall" type="date" class="tb-input tb-input-slim" wire:model="recallOn" min="{{ now()->toDateString() }}">
        <button type="submit" class="btn-tb btn-tb-ghost btn-tb-sm" wire:loading.attr="disabled" wire:target="saveRecall">Save</button>
        <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="saveRecall('default')">In {{ \App\Services\DentalService::RECALL_MONTHS }} months</button>
        @if($recallOn)<span class="muted tb-xs">Recall message goes out when it is due.</span>@endif
      </form>
      @error('recallOn')<p class="tb-field-error">{{ $message }}</p>@enderror
    </div>
  </div>

  {{-- ── History ─────────────────────────────────────────────── --}}
  @if($this->history->isNotEmpty())
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Chart history</span></div>
      <div class="tb-table-wrap"><table class="tb-table">
        <caption class="sr-only">Every finding recorded, newest first</caption>
        <thead><tr><th>When</th><th>Tooth</th><th>Finding</th><th>By</th></tr></thead>
        <tbody>
          @foreach($this->history as $e)
            <tr wire:key="dch-{{ $e->uuid }}">
              <td class="tb-nowrap tb-small">{{ $e->recorded_at->format('d M Y H:i') }}</td>
              <td class="mono">{{ $e->tooth }}{{ $e->surfaces ? ' '.$e->surfaces : '' }}</td>
              <td><x-ui.badge :tone="$e->finding->tone()">{{ $e->finding->label() }}@if($e->mobility !== null) {{ $e->mobility }}@endif</x-ui.badge>@if($e->notes)<span class="muted tb-xs"> {{ $e->notes }}</span>@endif</td>
              <td class="tb-small">{{ $e->recordedBy?->name }}</td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    </div>
  @endif

  <x-ui.modal show="showPlan" size="md" title="Plan a procedure">
    @if($showPlan)
      <form wire:submit="addToPlan" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Procedure" for="plan-service" name="serviceId" required>
            <select id="plan-service" class="tb-select" wire:model.live="serviceId">
              <option value="">Choose from the price list…</option>
              @foreach($this->services as $svc)
                <option value="{{ $svc->id }}">{{ $svc->name }} · {{ $svc->priceLabel() }}</option>
              @endforeach
            </select>
          </x-ui.field>
          <div class="tb-form-grid">
            <x-ui.field label="Tooth" for="plan-tooth" name="planTooth"><input id="plan-tooth" type="number" class="tb-input" wire:model="planTooth" min="11" max="85"></x-ui.field>
            <x-ui.field label="Surfaces" for="plan-surf" name="planSurfaces"><input id="plan-surf" type="text" class="tb-input" wire:model="planSurfaces" maxlength="6" placeholder="MO"></x-ui.field>
            @php($chosen = $serviceId ? $this->services->firstWhere('id', $serviceId) : null)
            @if($chosen && $chosen->max_price)
              <x-ui.field label="Price ({{ $chosen->priceLabel() }})" for="plan-price" name="planPrice"><input id="plan-price" type="number" step="any" class="tb-input" wire:model="planPrice" placeholder="{{ (float) $chosen->price }}"></x-ui.field>
            @endif
          </div>
          <x-ui.field label="Note" for="plan-note" name="planNotes"><input id="plan-note" type="text" class="tb-input" wire:model="planNotes" maxlength="500"></x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showPlan', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="addToPlan">Add to plan</button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
