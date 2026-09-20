@props(['label', 'for' => null, 'name' => null, 'required' => false, 'hint' => null, 'full' => false])
{{-- Label + control + error + hint. $name defaults to $for for @error lookup. --}}
@php($errKey = $name ?? $for)
<div {{ $attributes->merge(['class' => 'tb-form-group'.($full ? ' full' : '')]) }}>
  <label class="tb-label {{ $required ? 'tb-required' : '' }}" @if($for) for="{{ $for }}" @endif>{{ $label }}</label>
  {{ $slot }}
  @if($errKey)@error($errKey)<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror @endif
  {{-- Its own class rather than `muted tb-xs tb-mt-2`: a hint belongs to the
       control above it, and an 8px utility margin pushed it far enough away to
       read as a separate line of the form. --}}
  @if($hint)<div class="tb-field-hint">{{ $hint }}</div>@endif
</div>
