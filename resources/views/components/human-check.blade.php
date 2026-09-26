@props(['form'])
@php
  $humanId = \App\Support\HumanCheck::issue($form);
  $bad = $errors->has('human_answer') || $errors->has('human_started') || $errors->has('human_id') || $errors->has('website');
  $message = $errors->first('human_answer') ?: ($errors->first('human_started') ?: ($errors->first('human_id') ?: $errors->first('website')));
@endphp
{{--
  The picture check (App\Support\HumanCheck). The picture is drawn by this
  server for this one form and is spent by the first answer; the hidden
  field and the stamp catch what does not read pictures at all.
--}}
<div class="hc" data-human-check data-form="{{ $form }}" data-fresh="{{ route('human-check.new', ['form' => $form]) }}">
  <input type="hidden" name="human_id" value="{{ $humanId }}" data-hc-id>
  <input type="hidden" name="human_started" value="{{ \App\Support\HumanCheck::stamp($form) }}">
  <div class="hc-trap" aria-hidden="true">
    <label for="hc-website-{{ $form }}">Website</label>
    <input type="text" id="hc-website-{{ $form }}" name="website" tabindex="-1" autocomplete="off" value="">
  </div>

  <label class="hc-label" for="hc-answer-{{ $form }}">
    <i class="fas fa-shield-halved" aria-hidden="true"></i> Quick check — type the characters in the picture
  </label>
  <div class="hc-row">
    <div class="hc-pic">
      <img src="{{ route('human-check.image', $humanId) }}" width="{{ \App\Support\HumanCheck::WIDTH }}" height="{{ \App\Support\HumanCheck::HEIGHT }}"
           alt="A picture of five letters and numbers to type into the box" data-hc-img draggable="false">
      <button type="button" class="hc-new" data-hc-new title="Can't read it? Show a new picture" aria-label="Show a new picture">
        <i class="fas fa-rotate" aria-hidden="true"></i>
      </button>
    </div>
    <input class="hc-input @if($bad) is-bad @endif" id="hc-answer-{{ $form }}" name="human_answer" type="text"
           inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="8"
           placeholder="e.g. K7PX3" required
           @if($bad) aria-invalid="true" aria-describedby="hc-error-{{ $form }}" @endif>
  </div>
  @if($bad)
    <p class="hc-err" id="hc-error-{{ $form }}" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>
  @else
    <p class="hc-hint">Not case-sensitive. There is no 0, O, 1, I or L in it.</p>
  @endif
</div>
