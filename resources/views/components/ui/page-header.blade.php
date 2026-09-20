@props(['title', 'crumbs' => [], 'sr' => false])
{{--
  One <h1> per page (house rule 3). List pages pass :sr="true" (the topbar shows
  the title).

  The optional `explain` slot puts an (i) beside the heading. A page that opens
  with a paragraph about itself makes whoever read it once scroll past it every
  day afterwards; the words still belong somewhere, so they go behind that.

      <x-ui.page-header title="Stock ledger">
        <x-slot:explain>Everything that came in and everything that went out…</x-slot:explain>
      </x-ui.page-header>
--}}
<div class="tb-page-header">
  <div>
    <h1 @if($sr) class="sr-only" @endif>
      {{ $title }}
      @isset($explain)<x-ui.explain :label="'About '.$title">{{ $explain }}</x-ui.explain>@endisset
    </h1>
    @if($crumbs)<x-ui.breadcrumb :crumbs="$crumbs" />@endif
    {{ $subtitle ?? '' }}
  </div>
  @isset($actions)<div class="tb-flex">{{ $actions }}</div>@endisset
</div>
