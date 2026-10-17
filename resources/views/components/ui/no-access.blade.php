@props(['title' => null, 'icon' => 'fa-lock'])
{{--
  A part of a page this person's role does not open (Settings, Staff and
  access). Said plainly, with who can change it, never an empty space that
  looks like missing data.
--}}
<div {{ $attributes->merge(['class' => 'tb-card']) }}>
  @if($title)
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas {{ $icon }}" aria-hidden="true"></i> {{ $title }}</span></div>
  @endif
  <div class="tb-card-body">
    <p class="tb-empty tb-empty-sm" role="note">
      <i class="fas fa-lock" aria-hidden="true"></i>
      You do not have access to this. Ask your hospital admin.
    </p>
  </div>
</div>
