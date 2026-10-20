<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-tooth" aria-hidden="true"></i> Dental notes</span>
    <div class="tb-flex">
      @can('viewClinical', $this->visit)
        <a href="{{ route('admin.visits.report', $visit) }}" target="_blank" rel="noopener" class="btn-tb btn-tb-sm btn-tb-ghost"><i class="fas fa-file-pdf" aria-hidden="true"></i> Visit report</a>
      @endcan
      @can('diagnose', $this->visit)
        <button type="button" class="btn-tb btn-tb-sm" wire:click="openForm">
          <i class="fas {{ $this->notes['version'] > 0 ? 'fa-pen' : 'fa-plus' }}" aria-hidden="true"></i>
          {{ $this->notes['version'] > 0 ? 'Edit notes' : 'Write notes' }}
        </button>
      @endcan
    </div>
  </div>

  {{-- What was written: the complaint, then History and Oral examination,
       each heading only where something is written. --}}
  <div class="tb-card-body">
    @php($data = $this->notes['data'])
    <dl class="tb-notes">
      <dt>Presenting complaint</dt>
      <dd>@if(filled($this->visit->complaints)){!! nl2br(e($this->visit->complaints)) !!}@elseif(filled($this->visit->reason)){{ $this->visit->reason }}@else<span class="muted">Not written yet.</span>@endif</dd>
    </dl>

    @foreach(\App\Services\DentalNotesService::GROUPS as $group => $groupLabel)
      @php($keys = array_values(array_filter(\App\Services\DentalNotesService::keys($group), fn ($k) => ($data[$k] ?? '') !== '')))
      <h4 class="tb-label tb-mt-3">{{ $groupLabel }}</h4>
      @if($keys === [])
        <p class="muted tb-small">Not written yet.</p>
      @else
        <dl class="tb-notes">
          @foreach($keys as $key)
            <dt>{{ \App\Services\DentalNotesService::FIELDS[$key]['label'] }}</dt>
            <dd>{!! nl2br(e($data[$key])) !!}</dd>
          @endforeach
        </dl>
      @endif
    @endforeach

    @if($this->notes['carried'] !== [])
      <p class="muted tb-xs tb-mt-3"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> History from the last visit. Not saved on this visit yet.</p>
    @elseif($this->notes['recorded_at'])
      <p class="muted tb-xs tb-mt-3">Last saved {{ \Illuminate\Support\Carbon::parse($this->notes['recorded_at'])->format('d M Y H:i') }}@if($this->notes['recorded_by']) by {{ $this->notes['recorded_by'] }}@endif.</p>
    @endif
  </div>

  {{-- ── Writing them ───────────────────────────────────────────── --}}
  <x-ui.modal size="lg" show="showForm" title="Dental notes">
    @if($showForm)
      <form wire:submit="save" class="dn-form">
        <div class="tb-modal-body tb-vform">
          @error('form')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror

          <x-ui.field label="Presenting complaint" for="dn-complaints" name="complaints">
            <textarea id="dn-complaints" wire:model="complaints" class="tb-textarea" rows="2" maxlength="2000"
                      placeholder="In the patient's own words"></textarea>
          </x-ui.field>

          @foreach(\App\Services\DentalNotesService::GROUPS as $group => $groupLabel)
            <section class="tb-vform-step" wire:key="dnf-{{ $group }}">
              <h4 class="tb-step-h">{{ $groupLabel }}</h4>
              <div class="tb-form-grid">
                @foreach(\App\Services\DentalNotesService::keys($group) as $key)
                  <x-ui.field :label="\App\Services\DentalNotesService::FIELDS[$key]['label']" :for="'dn-'.$key" :name="'form.'.$key">
                    <textarea id="dn-{{ $key }}" wire:model="form.{{ $key }}" class="tb-textarea" rows="3" maxlength="2000"></textarea>
                  </x-ui.field>
                @endforeach
              </div>
            </section>
          @endforeach
        </div>

        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showForm', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save notes</span>
            <span wire:loading wire:target="save">Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
