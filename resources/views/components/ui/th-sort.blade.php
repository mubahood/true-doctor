@props(['field', 'sortField' => '', 'sortDir' => 'desc', 'align' => 'left'])
{{-- Sortable table header for WithTable components. --}}
<th class="sortable" wire:click="sortBy('{{ $field }}')" style="text-align:{{ $align }};"
    aria-sort="{{ $sortField === $field ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
  {{ $slot }}
  @if($sortField === $field)
    <i class="fas fa-caret-{{ $sortDir === 'asc' ? 'up' : 'down' }}" style="color:var(--br);" aria-hidden="true"></i>
  @else
    <i class="fas fa-sort" style="color:var(--mt2);font-size:.7em;opacity:.5;" aria-hidden="true"></i>
  @endif
</th>
