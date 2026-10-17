@php
  $p = $this->current;
  $s = $this->schedule;
  $d = fn ($v, $f = 'd M Y') => $v ? \Illuminate\Support\Carbon::parse($v)->format($f) : '—';
  $contactTone = ['done' => 'success', 'missed' => 'danger', 'due' => 'info', 'upcoming' => 'neutral'];
  $past = $this->pregnancies->filter(fn ($x) => $x->status !== \App\Enums\PregnancyStatus::Active);
@endphp
<div class="tb-stack">

  @if($p && $s)
    <div class="tb-stats-grid" role="group" aria-label="This pregnancy">
      <x-dash.stat icon="fa-person-pregnant" label="Gestation" :value="$s['gestation_label']" :sub="'Trimester '.$s['trimester']" />
      <x-dash.stat icon="fa-calendar-day" label="Expected delivery" :value="$d($p->edd, 'd M')" :sub="$p->edd_from_lmp ? 'From the LMP' : 'Set by the clinician'" />
      <x-dash.stat icon="fa-list-ol" label="Gravida / para" :value="'G'.$p->gravida.' P'.$p->para" />
      <x-dash.stat icon="fa-calendar-check" label="Next contact"
                   :value="$s['next'] ? 'Contact '.$s['next']['no'] : 'None left'"
                   :sub="$s['next'] ? 'Week '.$s['next']['week'].' · '.$d($s['next']['due_on'], 'd M') : ''"
                   :tone="$s['missed_contacts'] ? 'warn' : ''" />
    </div>

    @if($s['flags'])
      <div class="tb-alert tb-alert-warning" role="status">
        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
        <div><b>Risks</b>
          @foreach($s['flags'] as $flag)<x-ui.badge :tone="$flag['tone']">{{ $flag['label'] }}</x-ui.badge> @endforeach
          @if($p->risk_notes)<span class="muted tb-xs">{{ $p->risk_notes }}</span>@endif
        </div>
      </div>
    @endif

    <div class="tb-grid-2">
      <div class="tb-card">
        <div class="tb-card-header">
          <span class="tb-card-title">Due at this contact</span>
          @if($canRecord)<button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="openContact"><i class="fas fa-plus" aria-hidden="true"></i> Record contact</button>@endif
        </div>
        <div class="tb-card-body">
          @if($s['due_checks'])
            <ul class="mc-checklist">
              @foreach($s['due_checks'] as $c)<li wire:key="due-{{ $c['key'] }}"><i class="far fa-square" aria-hidden="true"></i> {{ $c['label'] }}</li>@endforeach
            </ul>
          @else
            <x-ui.empty icon="fa-circle-check" compact message="Nothing outstanding for this contact." />
          @endif
          @if($s['missed_checks'])
            <p class="tb-label tb-mt-3">Missed</p>
            <ul class="mc-checklist">
              @foreach($s['missed_checks'] as $c)<li wire:key="miss-{{ $c['key'] }}-{{ $c['contact'] }}"><x-ui.badge tone="danger">Contact {{ $c['contact'] }}</x-ui.badge> {{ $c['label'] }}</li>@endforeach
            </ul>
          @endif
        </div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header">
          <span class="tb-card-title">Pregnancy record</span>
          @if($canRecord)
            <div class="tb-flex">
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="openForm(true)"><i class="fas fa-pen" aria-hidden="true"></i> Edit</button>
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="openClose">Close</button>
            </div>
          @endif
        </div>
        <div class="tb-table-wrap"><table class="tb-table">
          <caption class="sr-only">Dating and the birth plan</caption>
          <tbody>
            <tr><th scope="row">Last period</th><td>{{ $d($p->lmp) }}</td></tr>
            <tr><th scope="row">Expected delivery</th><td>{{ $d($p->edd) }}</td></tr>
            <tr><th scope="row">Place of birth</th><td>{{ $p->birth_place ?: '—' }}</td></tr>
            <tr><th scope="row">Companion</th><td>{{ $p->birth_companion ?: '—' }}</td></tr>
            <tr><th scope="row">Transport</th><td>{{ $p->transport ?: '—' }}</td></tr>
            <tr><th scope="row">Blood donor</th><td>{{ $p->blood_donor ?: '—' }}</td></tr>
            @if($p->birth_plan_notes)<tr><th scope="row">Plan notes</th><td>{{ $p->birth_plan_notes }}</td></tr>@endif
          </tbody>
        </table></div>
      </div>
    </div>

    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Antenatal contacts</span></div>
      <div class="tb-table-wrap"><table class="tb-table">
        <caption class="sr-only">The contact schedule and what was found at each contact</caption>
        <thead><tr><th>Contact</th><th>Due</th><th>Status</th><th>Seen</th><th>BP</th><th class="tb-text-right">Weight</th><th class="tb-text-right">Fundal</th><th class="tb-text-right">FHR</th><th>Done</th></tr></thead>
        <tbody>
          @foreach($s['contacts'] as $c)
            @php($seen = $p->contacts->where('contact_no', $c['no']))
            @if($seen->isEmpty())
              <tr wire:key="anc-slot-{{ $c['no'] }}">
                <td class="mono">{{ $c['no'] }} <span class="muted tb-xs">· {{ $c['week'] }} w</span></td>
                <td class="tb-nowrap tb-small">{{ $d($c['due_on'], 'd M') }}</td>
                <td><x-ui.badge :tone="$contactTone[$c['status']]">{{ ucfirst($c['status']) }}</x-ui.badge></td>
                <td colspan="6" class="muted tb-xs">—</td>
              </tr>
            @else
              @foreach($seen as $row)
                <tr wire:key="anc-{{ $row->uuid }}">
                  <td class="mono">{{ $c['no'] }} <span class="muted tb-xs">· {{ $c['week'] }} w</span></td>
                  <td class="tb-nowrap tb-small">{{ $d($c['due_on'], 'd M') }}</td>
                  <td><x-ui.badge tone="success">Done</x-ui.badge></td>
                  <td class="tb-nowrap tb-small">{{ $d($row->seen_on, 'd M') }} <span class="muted tb-xs">{{ \App\Services\PregnancyService::gestationLabel($row->gestation_days) }}</span></td>
                  <td class="mono">{{ $row->blood_pressure ?? '—' }}</td>
                  <td class="tb-text-right mono">{{ $row->weight ?? '—' }}</td>
                  <td class="tb-text-right mono">{{ $row->fundal_height ?? '—' }}</td>
                  <td class="tb-text-right mono">{{ $row->fetal_heart_rate ?? '—' }}</td>
                  <td class="tb-xs">{{ collect((array) $row->checks)->map(fn ($k) => $checkLabels[$k] ?? $k)->implode(', ') ?: '—' }}@if($row->notes)<div class="muted">{{ $row->notes }}</div>@endif</td>
                </tr>
              @endforeach
            @endif
          @endforeach
        </tbody>
      </table></div>
    </div>
  @else
    <div class="tb-card">
      <div class="tb-card-header">
        <span class="tb-card-title"><i class="fas fa-person-pregnant" aria-hidden="true"></i> Pregnancy</span>
        @if($canRecord)<button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="openForm"><i class="fas fa-plus" aria-hidden="true"></i> Record a pregnancy</button>@endif
      </div>
      <div class="tb-card-body"><x-ui.empty icon="fa-person-pregnant" compact message="No current pregnancy." /></div>
    </div>
  @endif

  @if($past->isNotEmpty())
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Earlier pregnancies</span></div>
      <div class="tb-table-wrap"><table class="tb-table">
        <caption class="sr-only">Earlier pregnancies</caption>
        <thead><tr><th>Expected</th><th>Ended</th><th>Outcome</th><th>G / P</th><th class="tb-text-right">Contacts</th></tr></thead>
        <tbody>
          @foreach($past as $x)
            <tr wire:key="preg-{{ $x->uuid }}">
              <td class="tb-small">{{ $d($x->edd) }}</td>
              <td class="tb-small">{{ $d($x->ended_on) }}</td>
              <td><x-ui.badge :tone="$x->status->tone()">{{ $x->status->label() }}</x-ui.badge>@if($x->outcome_notes)<span class="muted tb-xs"> {{ $x->outcome_notes }}</span>@endif</td>
              <td class="mono">G{{ $x->gravida }} P{{ $x->para }}</td>
              <td class="tb-text-right mono">{{ $x->contacts->count() }}</td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    </div>
  @endif

  <x-ui.modal show="showForm" size="md" :title="$editing ? 'Edit pregnancy record' : 'Record a pregnancy'">
    @if($showForm)
      <form wire:submit="save" class="mc-form">
        <div class="tb-modal-body">
          <div class="tb-form-grid">
            <x-ui.field label="Last menstrual period" for="preg-lmp" name="lmp">
              <input id="preg-lmp" type="date" class="tb-input" wire:model.live.debounce.400ms="lmp" max="{{ now()->toDateString() }}">
            </x-ui.field>
            <x-ui.field label="Expected delivery" for="preg-edd" name="edd" hint="From the LMP; change it after a scan.">
              <input id="preg-edd" type="date" class="tb-input" wire:model="edd">
            </x-ui.field>
            <x-ui.field label="Gravida" for="preg-g" name="gravida" required hint="Pregnancies, this one included.">
              <input id="preg-g" type="number" min="1" max="30" class="tb-input" wire:model="gravida">
            </x-ui.field>
            <x-ui.field label="Para" for="preg-p" name="para" hint="Births before this pregnancy.">
              <input id="preg-p" type="number" min="0" max="29" class="tb-input" wire:model="para">
            </x-ui.field>
          </div>
          <fieldset class="mc-fieldset">
            <legend class="tb-label">Risk factors</legend>
            @foreach($riskLabels as $key => $label)
              <label class="tb-chip-check"><input type="checkbox" value="{{ $key }}" wire:model="riskFactors"> {{ $label }}</label>
            @endforeach
          </fieldset>
          <x-ui.field label="Risk notes" for="preg-rn" name="riskNotes"><input id="preg-rn" type="text" class="tb-input" wire:model="riskNotes" maxlength="500"></x-ui.field>
          <p class="tb-label tb-mt-3">Birth plan</p>
          <div class="tb-form-grid">
            <x-ui.field label="Place of birth" for="preg-bp" name="birthPlace"><input id="preg-bp" type="text" class="tb-input" wire:model="birthPlace" maxlength="120"></x-ui.field>
            <x-ui.field label="Companion" for="preg-bc" name="birthCompanion"><input id="preg-bc" type="text" class="tb-input" wire:model="birthCompanion" maxlength="120"></x-ui.field>
            <x-ui.field label="Transport" for="preg-tr" name="transport"><input id="preg-tr" type="text" class="tb-input" wire:model="transport" maxlength="120"></x-ui.field>
            <x-ui.field label="Blood donor" for="preg-bd" name="bloodDonor"><input id="preg-bd" type="text" class="tb-input" wire:model="bloodDonor" maxlength="120"></x-ui.field>
          </div>
          <x-ui.field label="Plan notes" for="preg-pn" name="birthPlanNotes"><input id="preg-pn" type="text" class="tb-input" wire:model="birthPlanNotes" maxlength="500"></x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showForm', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">Save</button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  <x-ui.modal show="showContact" size="md" title="Record an antenatal contact">
    @if($showContact && $s)
      <form wire:submit="saveContact" class="mc-form">
        <div class="tb-modal-body">
          <div class="tb-form-grid">
            <x-ui.field label="Seen on" for="anc-on" name="seenOn"><input id="anc-on" type="date" class="tb-input" wire:model="seenOn" max="{{ now()->toDateString() }}"></x-ui.field>
            <x-ui.field label="Blood pressure" for="anc-bp" name="bloodPressure"><input id="anc-bp" type="text" class="tb-input" wire:model="bloodPressure" placeholder="120/80" maxlength="12"></x-ui.field>
            <x-ui.field label="Weight (kg)" for="anc-w" name="weight"><input id="anc-w" type="number" step="0.1" class="tb-input" wire:model="weight"></x-ui.field>
            <x-ui.field label="Fundal height (cm)" for="anc-fh" name="fundalHeight"><input id="anc-fh" type="number" class="tb-input" wire:model="fundalHeight"></x-ui.field>
            <x-ui.field label="Fetal heart rate" for="anc-fhr" name="fetalHeart"><input id="anc-fhr" type="number" class="tb-input" wire:model="fetalHeart"></x-ui.field>
          </div>
          <fieldset class="mc-fieldset">
            <legend class="tb-label">Done at this contact</legend>
            @php($dueKeys = array_column($s['due_checks'], 'key'))
            @foreach($checkLabels as $key => $label)
              <label @class(['tb-chip-check', 'mc-due' => in_array($key, $dueKeys, true)])><input type="checkbox" value="{{ $key }}" wire:model="checks"> {{ $label }}</label>
            @endforeach
          </fieldset>
          @error('checks')<p class="tb-field-error">{{ $message }}</p>@enderror
          <x-ui.field label="Notes" for="anc-n" name="contactNotes"><input id="anc-n" type="text" class="tb-input" wire:model="contactNotes" maxlength="500"></x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showContact', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveContact">Record</button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  <x-ui.modal show="showClose" size="sm" title="Close this pregnancy">
    @if($showClose)
      <form wire:submit="close" class="mc-form">
        <div class="tb-modal-body">
          <x-ui.field label="Outcome" for="preg-out" name="closeStatus">
            <select id="preg-out" class="tb-select" wire:model="closeStatus">
              <option value="delivered">Delivered</option>
              <option value="ended">Ended otherwise (loss, transfer)</option>
            </select>
          </x-ui.field>
          <x-ui.field label="On" for="preg-end" name="endedOn"><input id="preg-end" type="date" class="tb-input" wire:model="endedOn" max="{{ now()->toDateString() }}"></x-ui.field>
          <x-ui.field label="Notes" for="preg-on" name="outcomeNotes"><input id="preg-on" type="text" class="tb-input" wire:model="outcomeNotes" maxlength="500"></x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showClose', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="close">Close pregnancy</button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
