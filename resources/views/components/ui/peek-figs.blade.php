@props(['figures'])
{{--
  The two or three figures somebody opened the dialog for, before the facts
  that explain them.

  A dialog earns its place by carrying the number the ROW made a reader work
  out in their head — what is still spendable, how many nights so far, what is
  left unpaid. Burying that in a definition list means reading six rows to
  answer one question.

  Each figure: ['label' => string, 'value' => string, 'bad' => bool, 'sub' => ?string]

  Usage:
      <x-ui.peek-figs :figures="[
        ['label' => 'Nights so far', 'value' => '11'],
        ['label' => 'Accrued', 'value' => $money, 'bad' => true],
      ]" />
--}}
@php($figures = array_values(array_filter($figures)))
@if($figures !== [])
  <div class="tb-peek-figs" @style(['grid-template-columns:repeat('.count($figures).',1fr)' => count($figures) !== 3])>
    @foreach($figures as $fig)
      <div class="tb-peek-fig">
        <span @class(['tb-peek-fig-n', 'is-bad' => $fig['bad'] ?? false])>{{ $fig['value'] }}</span>
        <span class="tb-peek-fig-l">{{ $fig['label'] }}</span>
        @if(($fig['sub'] ?? null) !== null)
          <span class="tb-peek-fig-s">{{ $fig['sub'] }}</span>
        @endif
      </div>
    @endforeach
  </div>
@endif
