@props(['rows', 'loop'])
{{--
  The row's number on the list, counting across pages.

  A table of twenty-five near-identical drug names is hard to talk about: "the
  third one" means nothing once somebody else has sorted it differently, and
  reading a name back over a phone is how the wrong thing gets ordered. A
  number gives every row something short to point at.

  It counts THROUGH pagination — row 21 on page two is #21, not #1 — because a
  serial that restarts is not one.
--}}
<td class="tb-serial mono">{{ $rows->firstItem() + $loop->index }}</td>
