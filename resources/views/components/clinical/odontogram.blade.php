@props(['chart' => [], 'primary' => false, 'selected' => null, 'action' => 'pick'])
{{--
  The FDI odontogram, as the dentist faces the patient: upper row on top, the
  patient's right on the left. A tap calls `action(tooth)` on the component,
  which opens the tooth's dialog. `chart` is DentalService::chart()'s shape.
  Used by the patient's Dental tab, the visit page and the new visit form, so
  all three look and behave alike.
--}}
<div class="odonto" role="grid" aria-label="Teeth, FDI notation">
  @foreach(\App\Services\DentalService::rows((bool) $primary) as $r => [$right, $left])
    <div class="odonto-row {{ $r === 0 ? 'upper' : 'lower' }}" role="row">
      @foreach([$right, $left] as $side => $teeth)
        <div class="odonto-half {{ $side === 0 ? 'right' : 'left' }}">
          @foreach($teeth as $t)
            @php($s = $chart[$t] ?? null)
            <button type="button" role="gridcell" wire:click="{{ $action }}({{ $t }})" wire:key="tooth-{{ $t }}"
                    @class(['tooth', 'on' => $selected === $t, 'f-'.($s['finding'] ?? 'none'), 'has-surf' => ! empty($s['surfaces'])])
                    aria-pressed="{{ $selected === $t ? 'true' : 'false' }}"
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
