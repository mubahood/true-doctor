@props([
    'label' => 'Prices in',
])
@php($region = app(\App\Support\VisitorRegion::class))
@php($base = strtoupper((string) config('pricing.base_currency', 'UGX')))
{{--
  Two buttons, one of them on.

  The site guesses a currency from where the reader appears to be. A guess
  needs a way to be wrong in public, so this is always on the page and always
  wins — and because it posts and reloads, it works with no JavaScript at all.
--}}
<form method="POST" action="{{ route('currency') }}" class="foot-cur">
  @csrf
  <span class="cur-note">{{ $label }}</span>
  <span class="cur" role="group" aria-label="Choose a currency">
    <button type="submit" name="currency" value="{{ $base }}"
            class="{{ $region->currency() === $base ? 'on' : '' }}"
            @if($region->currency() === $base) aria-current="true" @endif>
      USh
    </button>
    <button type="submit" name="currency" value="USD"
            class="{{ $region->currency() === 'USD' ? 'on' : '' }}"
            @if($region->currency() === 'USD') aria-current="true" @endif>
      USD
    </button>
  </span>
</form>
