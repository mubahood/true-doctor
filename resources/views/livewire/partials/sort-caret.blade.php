@if($sortField === $field)
  <i class="fas fa-caret-{{ $sortDir === 'asc' ? 'up' : 'down' }}" style="margin-left:2px;color:var(--br);"></i>
@else
  <i class="fas fa-sort" style="margin-left:2px;color:var(--mt2);font-size:.7em;opacity:.5;"></i>
@endif
