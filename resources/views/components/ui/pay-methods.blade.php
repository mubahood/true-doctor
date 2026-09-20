{{--
  What a hospital can actually pay with, shown wherever a price is.
  Visa and Mastercard are the self-hosted Font Awesome brand marks; MTN and
  Airtel have no icon font, so they are small brand-coloured wordmarks — a
  recognisable badge, not a reproduction of the official logo artwork.
--}}
<div {{ $attributes->merge(['class' => 'pay-methods']) }} aria-label="Pay by mobile money or card">
  <span class="muted tb-xs">Pay with</span>
  <span class="pay-mark is-mtn" title="MTN Mobile Money">MTN</span>
  <span class="pay-mark is-airtel" title="Airtel Money">airtel</span>
  <i class="fab fa-cc-visa pay-card is-visa" title="Visa" aria-hidden="true"></i>
  <i class="fab fa-cc-mastercard pay-card is-mc" title="Mastercard" aria-hidden="true"></i>
  <span class="sr-only">MTN Mobile Money, Airtel Money, Visa and Mastercard accepted.</span>
</div>
