@props(['title', 'rows', 'empty' => 'Nothing yet.'])
{{--
  A section of a quick view holding a short list of things that happened, most
  recent first — movements, entries, status changes, notes.

  `rows` is what the caller counted; the slot is the <li>s it rendered. Passing
  both means the "nothing here" sentence is written once per screen instead of
  being an @if wrapped round every section in every dialog.

  Capped by whoever fills it: the whole story is the record's own page, and a
  dialog that loads five hundred rows to show six opens slowly.
--}}
<div class="tb-peek-sec">{{ $title }}</div>
@if(count($rows) > 0)
  <ul class="tb-peek-trail">{{ $slot }}</ul>
@else
  <p class="tb-out-none">{{ $empty }}</p>
@endif
