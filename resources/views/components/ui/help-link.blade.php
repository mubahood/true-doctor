{{--
  "Help" for the screen being shown: the guide App\Support\HelpGuides maps to
  this route, or the list of guides. A public page, so it opens in a new tab
  and nothing typed on this screen is lost.
--}}
@php($help = \App\Support\HelpGuides::linkFor(\App\Support\HelpGuides::currentRouteName()))
<a href="{{ $help['url'] }}" target="_blank" rel="noopener" {{ $attributes->merge(['class' => 'tb-help-link']) }} title="{{ $help['label'] }}">
  <i class="fas fa-circle-question" aria-hidden="true"></i> Help<span class="sr-only">: {{ $help['label'] }} (opens in a new tab)</span>
</a>
