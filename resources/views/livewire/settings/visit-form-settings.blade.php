<div class="tb-card tb-mt-3">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-file-medical" aria-hidden="true"></i> Visit form</span>
    @if($form->vitalsCustomised || $form->dentalNotesCustomised)
      <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="useDefaults">Use the defaults for your practice</button>
    @endif
  </div>
  <form wire:submit="save" class="tb-card-body tb-vform">
    <fieldset class="tb-kinds">
      <legend class="tb-label">Vital signs recorded</legend>
      <p class="muted tb-small">The new visit form and the visit page ask only for these. A reading already taken on a visit is always kept and shown.</p>
      <div class="cs-chips" role="group" aria-label="Vital signs recorded">
        @foreach(\App\Support\Practice\VisitForm::VITALS as $key => $label)
          <label class="tb-chip-check" wire:key="vf-{{ $key }}"><input type="checkbox" value="{{ $key }}" wire:model="vitals"> {{ $label }}</label>
        @endforeach
      </div>
      @error('vitals')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
    </fieldset>

    @if($form->dentistry)
      <fieldset class="tb-kinds tb-mt-3">
        <legend class="tb-label">Dental notes</legend>
        <div class="tb-seg-cards" role="radiogroup" aria-label="Dental notes">
          <label @class(['tb-seg-opt', 'is-on' => $dentalNotes === \App\Support\Practice\VisitForm::SIMPLE])>
            <input type="radio" wire:model.live="dentalNotes" value="{{ \App\Support\Practice\VisitForm::SIMPLE }}" class="sr-only">
            <span>Simple notes</span><em>Presenting complaint, History and Oral examination as plain text</em>
          </label>
          <label @class(['tb-seg-opt', 'is-on' => $dentalNotes === \App\Support\Practice\VisitForm::DETAILED])>
            <input type="radio" wire:model.live="dentalNotes" value="{{ \App\Support\Practice\VisitForm::DETAILED }}" class="sr-only">
            <span>Detailed case sheet</span><em>Structured sections, coded diagnoses, X-rays and instructions</em>
          </label>
        </div>
        <p class="muted tb-small tb-mt-3">A visit already written keeps the way it was written.</p>
      </fieldset>
    @endif

    <div class="tb-flex tb-mt-3">
      <button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="save">
        <i class="fas fa-check" aria-hidden="true"></i> Save visit form
      </button>
    </div>
  </form>
</div>
