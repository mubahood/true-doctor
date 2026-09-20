<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-notes-medical" aria-hidden="true"></i> Clinical</span>
    @can('diagnose', $this->visit)
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openForm">
        <i class="fas {{ $this->visit->diagnosis ? 'fa-pen' : 'fa-plus' }}" aria-hidden="true"></i>
        {{ $this->visit->diagnosis ? 'Edit notes' : 'Write notes' }}
      </button>
    @endcan
  </div>

  {{-- What the doctor found. Everyone reads this; writing it opens the dialog. --}}
  <div class="tb-card-body">
    @if($this->visit->complaints || $this->visit->diagnosis || $this->visit->doctor_remarks)
      <dl class="tb-notes">
        @foreach(['Complaints' => $this->visit->complaints,
                  'Diagnosis' => $this->visit->diagnosis,
                  "Doctor's remarks" => $this->visit->doctor_remarks] as $label => $text)
          @if($text)
            <dt>{{ $label }}</dt><dd>{{ $text }}</dd>
          @endif
        @endforeach
      </dl>
    @else
      <x-ui.empty compact icon="fa-notes-medical" message="Nothing written on this visit yet." />
    @endif
  </div>

  {{-- ── Writing them ───────────────────────────────────────────── --}}
  <x-ui.modal size="lg" show="showForm" title="Clinical notes">
    @if($showForm)
      @php
        $ctx = $this->context;
        $phrases = $this->phrases;
        $textFields = [
          ['complaints', 'Complaints', 'cl-complaints', 2, 'What the patient says is wrong, in their words'],
          ['diagnosis', 'Diagnosis', 'cl-diagnosis', 3, 'What you think it is'],
          ['doctor_remarks', "Doctor's remarks", 'cl-remarks', 2, 'The plan, and anything the next person needs'],
        ];
      @endphp

      <form wire:submit="save" style="display:contents;">
        <div class="tb-modal-body">

          {{-- ── What should be in front of you while writing ───────────
               None of this is new. It was all on the record already, and
               nobody was showing it at the moment it matters. --}}
          @if($ctx['allergies'] || $ctx['conditions'] || $ctx['vitals'] || $ctx['previous'])
            <div class="tb-ctx">
              @if($ctx['allergies'])
                <div class="tb-ctx-row is-alert">
                  <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                  <span class="k">Allergies</span>
                  <span class="v">{{ implode(' · ', $ctx['allergies']) }}</span>
                </div>
              @endif
              @if($ctx['conditions'])
                <div class="tb-ctx-row">
                  <i class="fas fa-heart-pulse" aria-hidden="true"></i>
                  <span class="k">Ongoing</span>
                  <span class="v">{{ implode(' · ', $ctx['conditions']) }}</span>
                </div>
              @endif
              @if($ctx['vitals'])
                <div class="tb-ctx-row">
                  <i class="fas fa-stethoscope" aria-hidden="true"></i>
                  <span class="k">Vitals</span>
                  <span class="v">{{ implode('  ·  ', $ctx['vitals']) }}</span>
                </div>
              @endif
              @if($ctx['previous'])
                <div class="tb-ctx-row">
                  <i class="fas fa-clock-rotate-left" aria-hidden="true"></i>
                  <span class="k">Last seen</span>
                  <span class="v">{{ $ctx['previous']['diagnosis'] }}
                    <em>· {{ $ctx['previous']['when'] }}</em></span>
                </div>
              @endif
            </div>
          @endif

          <x-ui.field label="Doctor" name="doctor_user_id"
                      :hint="$this->visit->department?->name ? 'Narrowed to '.$this->visit->department->name : null">
            {{-- Searched, never the whole staff list rendered into a select. --}}
            <livewire:ui.select-search resource="doctors" name="doctor_user_id"
                                       :selected="$doctor_user_id"
                                       :scope="$this->visit->department_id"
                                       placeholder="Search doctors…"
                                       :key="'cl-doctor-'.$visitId.'-'.$formNonce" />
          </x-ui.field>

          @foreach($textFields as [$name, $label, $id, $rows, $placeholder])
            <x-ui.field :label="$label" :for="$id" :name="$name">
              <textarea id="{{ $id }}" class="tb-textarea" rows="{{ $rows }}" maxlength="2000"
                        placeholder="{{ $placeholder }}"
                        wire:model.blur="{{ $name }}"></textarea>

              {{-- A typing aid, never a menu to pick a diagnosis from: each one
                   lands as editable text, and clicking a second appends rather
                   than replacing, because a patient presents with more than
                   one thing. --}}
              @if($phrases[$name] ?? false)
                <div class="tb-picker-pills tb-phrase-pills">
                  @foreach($phrases[$name] as $phrase)
                    {{-- Lit means it is already in the field: the row of pills
                         doubles as a reading of what has been chosen, and a
                         second click takes one back out. --}}
                    <button type="button"
                            @class([
                                'tb-pill',
                                'is-from-desk' => $phrase['source'] === 'reception',
                                'is-on' => $this->hasPhrase($name, $phrase['value']),
                            ])
                            wire:key="cp-{{ $name }}-{{ $loop->index }}"
                            wire:click="usePhrase('{{ $name }}', @js($phrase['value']))"
                            title="{{ $phrase['source'] === 'reception' ? 'What reception wrote on this visit' : $phrase['label'] }}">
                      @if($phrase['source'] === 'reception')
                        <i class="fas fa-file-pen" aria-hidden="true"></i>
                      @endif
                      {{ $phrase['label'] }}
                    </button>
                  @endforeach
                </div>
              @endif
            </x-ui.field>
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
