@props(['href' => null, 'label' => 'Full record', 'icon' => 'fa-arrow-up-right-from-square'])
{{--
  The bottom of a quick view.

  The way OUT sits left, away from the buttons that act — so the click that
  closes a dialog is never next to the click that changes a record. Whatever a
  component puts in the default slot goes on the left, beside Close; the link
  to the full record is the primary button on the right, and is the ONE
  navigation a quick view is allowed to offer.
--}}
<div class="tb-modal-foot tb-peek-foot">
  {{ $slot }}
  <span class="tb-peek-gap"></span>
  <button type="button" class="btn-tb btn-tb-ghost" wire:click="closePeek">Close</button>
  {{ $actions ?? '' }}
  @if($href)
    <a class="btn-tb btn-tb-primary" wire:navigate href="{{ $href }}">
      <i class="fas {{ $icon }}" aria-hidden="true"></i> {{ $label }}
    </a>
  @endif
</div>
