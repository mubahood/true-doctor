{{--
  The tooth chart on paper, as a dentist draws it on a case sheet: the upper
  teeth on top, the lower below, the patient's right on the reader's left,
  FDI numbers, and in each box the letters of what was found (C caries,
  F filled, M missing, X to extract...) with the surfaces. A plain table,
  because DomPDF draws tables well and nothing else here needs to.

  $chart: DentalService::chart(), keyed by tooth.
--}}
@php
  $cell = function (?array $s): string {
      if ($s === null) {
          return '';
      }
      $parts = [];
      if ($s['finding']) {
          $parts[] = \App\Enums\DentalFinding::from($s['finding'])->code().($s['mobility'] !== null ? $s['mobility'] : '');
      }
      foreach (collect($s['surfaces'])->groupBy(fn ($f) => $f, true) as $finding => $surfaces) {
          $parts[] = \App\Enums\DentalFinding::from($finding)->code().' '.implode('', array_values(array_intersect(\App\Services\DentalService::SURFACES, array_keys($surfaces->all()))));
      }
      if ($parts === [] && $s['notes'] !== []) {
          $parts[] = 'N';
      }

      return implode(' ', $parts);
  };
  $hasPrimary = collect(array_keys($chart))->contains(fn ($t) => $t >= 51);
  $rows = [
      [[18, 17, 16, 15, 14, 13, 12, 11], [21, 22, 23, 24, 25, 26, 27, 28]],
      [[48, 47, 46, 45, 44, 43, 42, 41], [31, 32, 33, 34, 35, 36, 37, 38]],
  ];
  if ($hasPrimary) {
      array_splice($rows, 1, 0, [[[55, 54, 53, 52, 51], [61, 62, 63, 64, 65]], [[85, 84, 83, 82, 81], [71, 72, 73, 74, 75]]]);
  }
@endphp
<table class="odonto-pdf">
  @foreach($rows as [$right, $left])
    <tr>
      @if(count($right) === 5)<td class="gap" colspan="3"></td>@endif
      @foreach($right as $t)
        @php($text = $cell($chart[$t] ?? null))
        <td @class(['on' => $text !== ''])><span class="n">{{ $t }}</span><br>{{ $text }}</td>
      @endforeach
      <td class="mid"></td>
      @foreach($left as $t)
        @php($text = $cell($chart[$t] ?? null))
        <td @class(['on' => $text !== ''])><span class="n">{{ $t }}</span><br>{{ $text }}</td>
      @endforeach
      @if(count($left) === 5)<td class="gap" colspan="3"></td>@endif
    </tr>
  @endforeach
</table>
<p class="muted small">C caries · F filled · M missing · X to extract · Cr crown · Br bridge · Im implant · RC root canal · Fr fracture · Mo mobility · N note. Surfaces: M mesial, O occlusal, D distal, B buccal, L lingual, I incisal.</p>
@php($noted = collect($chart)->filter(fn ($s) => $s['notes'] !== []))
@if($noted->isNotEmpty())
  <p class="small">
    @foreach($noted as $t => $s)<b>{{ $t }}</b> {{ implode('; ', $s['notes']) }}@if(! $loop->last) · @endif @endforeach
  </p>
@endif
