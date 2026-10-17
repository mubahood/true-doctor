@php
  $d = fn ($v, $f = 'd M Y') => $v ? \Illuminate\Support\Carbon::parse($v)->format($f) : '—';
  $groups = collect($this->card)->groupBy('age_label');
@endphp
<div class="tb-stack">
  @if($this->patient->dob === null)
    <div class="tb-alert tb-alert-warning" role="status">
      <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
      <div><b>No date of birth.</b> <span class="muted">The schedule runs from it: record it by editing the patient.</span></div>
    </div>
  @else
    <div class="tb-stats-grid" role="group" aria-label="Immunisation">
      <x-dash.stat icon="fa-syringe" label="Given" :value="(string) $counts['given']" :sub="'of '.count($this->card).' doses'" tone="ok" />
      <x-dash.stat icon="fa-calendar-day" label="Due now" :value="(string) $counts['due']" :tone="$counts['due'] ? 'warn' : ''" />
      <x-dash.stat icon="fa-triangle-exclamation" label="Overdue" :value="(string) $counts['overdue']" :tone="$counts['overdue'] ? 'bad' : ''" />
      <x-dash.stat icon="fa-forward" label="Next" :value="$counts['next'] ? $counts['next']['vaccine'] : 'Complete'" :sub="$counts['next'] ? $counts['next']['dose'].' · '.$d($counts['next']['due_on'], 'd M Y') : null" />
    </div>

    <div class="tb-card">
      <div class="tb-card-header">
        <span class="tb-card-title"><i class="fas fa-syringe" aria-hidden="true"></i> Immunisation card</span>
        <span class="muted tb-xs">National schedule (UNEPI)</span>
      </div>
      @if($this->card === [])
        <div class="tb-card-body"><x-ui.empty icon="fa-syringe" compact message="No doses on the schedule for this patient." /></div>
      @else
        <div class="tb-table-wrap"><table class="tb-table">
          <caption class="sr-only">Every dose of the schedule and its status</caption>
          <thead><tr><th>Age</th><th>Vaccine</th><th>Dose</th><th>Due</th><th>Status</th><th>Given</th><th><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
            @foreach($groups as $age => $rows)
              @foreach($rows as $i => $r)
                <tr wire:key="imm-{{ $r['key'] }}">
                  <td class="tb-small tb-nowrap">@if($i === 0)<b>{{ $age }}</b>@endif</td>
                  <td>{{ $r['vaccine'] }}</td>
                  <td class="tb-small">{{ $r['dose'] }}</td>
                  <td class="tb-small tb-nowrap">{{ $d($r['due_on'], 'd M Y') }}</td>
                  <td><x-ui.badge :tone="$r['tone']">{{ ucfirst($r['status']) }}</x-ui.badge></td>
                  <td class="tb-small tb-nowrap">
                    @if($r['given_on']){{ $d($r['given_on']) }}@if($r['given_elsewhere']) <span class="muted tb-xs">elsewhere</span>@endif @if($r['batch_no'])<span class="muted tb-xs mono">{{ $r['batch_no'] }}</span>@endif @else — @endif
                  </td>
                  <td class="tb-text-right tb-nowrap">
                    @if($canRecord)
                      @if($r['status'] === 'given')
                        <x-ui.icon-button label="Void {{ $r['vaccine'] }} {{ $r['dose'] }}" icon="fa-xmark" wire:click="openVoid('{{ $r['uuid'] }}')" />
                      @else
                        <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="openGive('{{ $r['key'] }}')">Give</button>
                      @endif
                    @endif
                  </td>
                </tr>
              @endforeach
            @endforeach
          </tbody>
        </table></div>
      @endif
    </div>
  @endif

  <x-ui.modal show="showGive" size="sm" :title="'Record '.$doseName">
    @if($showGive)
      <form wire:submit="give" class="mc-form">
        <div class="tb-modal-body">
          @error('doseKey')<p class="tb-field-error">{{ $message }}</p>@enderror
          <x-ui.field label="Given on" for="imm-on" name="givenOn"><input id="imm-on" type="date" class="tb-input" wire:model="givenOn" max="{{ now()->toDateString() }}"></x-ui.field>
          <x-ui.field label="Batch number" for="imm-batch" name="batchNo"><input id="imm-batch" type="text" class="tb-input" wire:model="batchNo" maxlength="40"></x-ui.field>
          <label class="tb-check-group tb-xs"><input type="checkbox" wire:model="givenElsewhere"> Given elsewhere (copied from the child health card)</label>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showGive', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="give">Record</button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  <x-ui.modal show="showVoid" size="sm" title="Void this entry">
    @if($showVoid)
      <form wire:submit="void" class="mc-form">
        <div class="tb-modal-body">
          <p class="tb-small muted">The entry is kept in the register with your reason, and the dose can be recorded again.</p>
          <x-ui.field label="Reason" for="imm-why" name="voidReason" required><input id="imm-why" type="text" class="tb-input" wire:model="voidReason" maxlength="255"></x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showVoid', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-danger" wire:loading.attr="disabled" wire:target="void">Void</button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
