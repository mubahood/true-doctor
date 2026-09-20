<div>
  {{-- This file uses the BLOCK form of Blade's php directive only. Blade runs
       storePhpBlocks BEFORE it strips comments, and that regex pairs the first
       opener it sees with the first closer it sees — so an inline php(...) above
       a block silently swallows the markup between them. (Writing the two
       directive names literally in a comment sets the same trap, which is why
       they are spelled out in prose here.) --}}
  @php
    $visit = $this->visit;
  @endphp

  <x-ui.modal show="show" :title="$visit?->patient?->full_name ?? 'Visit'" size="full">
    {{-- The thing a reader reaches for most, kept in the header so it is one
         click away however far down the visit they have scrolled. --}}
    @if($show && $visit && $visit->isOpen())
      <x-slot:actions>
        {{-- No scroll: the form opens over everything, so where the pane
             happens to be does not matter. (jump() lives on tdVisitPane inside
             the body and is not in scope up here.) --}}
        <button type="button" class="btn-tb btn-tb-sm btn-tb-primary"
                wire:click="$dispatchTo('visits.panels.orders', 'orders-add')">
          <i class="fas fa-plus" aria-hidden="true"></i> Add order
        </button>
      </x-slot:actions>
    @endif

    @if($show && $visit)
      @php
        $sections = $this->sections;
        $invoice = $visit->invoices->first();
        $admission = $visit->admissions->first();
      @endphp

      {{-- Which visit, and where it stands. The row that opened this is behind
           the backdrop, so the dialog has to say. --}}
      <div class="tb-modal-sub">
        <span class="mono">{{ $visit->visit_no }}</span>
        <x-ui.badge :tone="$visit->stateBadge()">{{ $visit->stateLabel() }}</x-ui.badge>
        <span class="sep">·</span>
        <span>{{ $visit->doctor?->name ?? 'No doctor assigned' }}</span>
        <span class="sep">·</span>
        <span>{{ $visit->created_at->format('d M Y · H:i') }}</span>
      </div>

      {{-- One scroll container, one rail beside it. tdVisitPane does the
           jumping and keeps the rail in step with what you are looking at;
           none of it costs a round trip. --}}
      <div class="tb-vs" x-data="tdVisitPane(@js($this->section))">

        <nav class="tb-vs-rail" aria-label="Visit sections">
          <ul>
            @foreach($sections as $key => $s)
              <li>
                <button type="button" :class="{ 'is-at': at === '{{ $key }}' }"
                        x-on:click="jump('{{ $key }}')"
                        :aria-current="at === '{{ $key }}' ? 'true' : 'false'">
                  <i class="fas {{ $s['icon'] }}" aria-hidden="true"></i>
                  <span>{{ $s['label'] }}</span>
                  @if($s['count'] !== null)
                    <b @class(['is-zero' => $s['count'] === 0])>{{ $s['count'] }}</b>
                  @endif
                </button>

              </li>
            @endforeach
          </ul>

          <div class="tb-vs-rail-foot">
            @can('manage', $visit)
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm tb-vs-move"
                      x-on:click="jump('summary')">
                <i class="fas fa-diagram-next" aria-hidden="true"></i> {{ $visit->stateLabel() }}
              </button>
            @endcan
            <x-ui.link :href="route('admin.visits.show', $visit)" class="btn-tb btn-tb-ghost btn-tb-sm">
              Full page <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </x-ui.link>
          </div>
        </nav>

        <div class="tb-vs-pane" x-ref="pane" tabindex="0">

          {{-- ── Summary ─────────────────────────────────────────────── --}}
          <section id="vs-summary" data-vs="summary" class="tb-vs-sec">
            <h3><i class="fas fa-circle-info" aria-hidden="true"></i> Summary</h3>

            <div class="tb-vs-summary">
              <dl class="tb-vs-facts">
                <div><dt>Patient</dt><dd>
                  @if($visit->patient)
                    <x-ui.link :href="route('admin.patients.show', $visit->patient)">{{ $visit->patient->full_name }}</x-ui.link>
                    <span class="s">{{ $visit->patient->patient_no }}@if($visit->patient->dob) · {{ $visit->patient->dob->age }}y @endif</span>
                  @else — @endif
                </dd></div>
                <div><dt>Doctor</dt><dd>{{ $visit->doctor?->name ?? '—' }}</dd></div>
                <div><dt>Department</dt><dd>{{ $visit->department?->name ?? '—' }}</dd></div>
                <div><dt>Arrived as</dt><dd>
                  @if($visit->appointment)
                    <x-ui.link :href="route('admin.appointments.show', $visit->appointment)">Appointment</x-ui.link>
                    <span class="s">{{ $visit->appointment->scheduled_at->format('d M · H:i') }}</span>
                  @else Walk-in @endif
                </dd></div>
              </dl>

              <div class="tb-vs-money">
                @if($invoice)
                  <span class="k">{{ $invoice->invoice_no }}</span>
                  <x-ui.money :amount="$invoice->total" class="v" />
                  @if(bccomp((string) $invoice->balance, '0.00', 2) <= 0)
                    <span class="ok"><i class="fas fa-circle-check" aria-hidden="true"></i> Settled</span>
                  @else
                    <span class="due">Balance <x-ui.money :amount="$invoice->balance" /></span>
                  @endif
                @else
                  <span class="k">Running total</span>
                  <x-ui.money :amount="$this->totals['total'] ?? '0'" class="v" />
                  <span class="k">Not yet invoiced</span>
                @endif
              </div>
            </div>

            {{-- Only the reason here: complaints, diagnosis and plan belong to
                 the clinical panel below, and printing them twice was exactly
                 the duplication this restructure is removing. --}}
            @if($visit->reason)
              <div class="tb-vs-notes">
                <p><span>Reason</span>{{ $visit->reason }}</p>
              </div>
            @endif

            @if($admission)
              <p class="tb-vs-admitted">
                <i class="fas fa-bed" aria-hidden="true"></i>
                Admitted — {{ $admission->bed?->ward?->name ?? 'ward' }}, bed {{ $admission->bed?->code ?? '—' }}
                <x-ui.link :href="route('admin.admissions.show', $admission)">View admission</x-ui.link>
              </p>
            @endif

            {{-- Vitals and the clinical notes are part of the summary of a
                 visit, not tabs beside it. Mounted whole, so each keeps its own
                 authorisation and its own recording dialog. --}}
            <div class="tb-vs-sub">
              @livewire('visits.panels.vitals', ['visitId' => $visit->id, 'lazy' => false], key('vs-vitals-'.$visit->id))
            </div>
            <div class="tb-vs-sub">
              @livewire('visits.panels.clinical', ['visitId' => $visit->id, 'lazy' => false], key('vs-clinical-'.$visit->id))
            </div>

          </section>

          {{-- ── The panels, each whole, all present ──────────────────── --}}
          @foreach($sections as $key => $s)
            @continue($s['component'] === null)
            <section id="vs-{{ $key }}" data-vs="{{ $key }}"
                     @class(['tb-vs-sec', 'is-empty' => $s['count'] === 0])>
              <h3><i class="fas {{ $s['icon'] }}" aria-hidden="true"></i> {{ $s['label'] }}</h3>
              @livewire($s['component'], ['visitId' => $visit->id, 'lazy' => false], key('vs-'.$key.'-'.$visit->id))
            </section>
          @endforeach

          {{-- ── History ─────────────────────────────────────────────── --}}
          <section id="vs-history" data-vs="history" class="tb-vs-sec">
            <h3><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> History</h3>
            <ol class="tb-vs-trail">
              @foreach($visit->history as $entry)
                <li @class(['is-override' => $entry->is_override])>
                  <span class="w">{{ $entry->created_at?->format('d M · H:i') }}</span>
                  <span class="s">
                    {{ $entry->fromLabel() }} → <b>{{ $entry->toLabel() }}</b>
                    @if($entry->is_override)
                      <span class="tb-trail-flag" title="Changed out of order, skipping the usual steps">
                        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i> out of order
                      </span>
                    @endif
                  </span>
                  @if($entry->note)<span class="n">{{ $entry->note }}</span>@endif
                  <span class="b">{{ $entry->changedBy?->name ?? '—' }}</span>
                </li>
              @endforeach
            </ol>
          </section>

          {{-- ── Where it goes next ──────────────────────────────────────
             Last, deliberately. This is the one control about the VISIT
             rather than about something on it, and it used to sit between
             the clinical notes and the orders — interrupting the record with
             a question that only makes sense once you have read it. --}}
          @can('manage', $visit)
            @php
              $gate = $this->gate;
            @endphp
            <div class="tb-vs-stage tb-gate">
              <div class="tb-gate-now">
                <span class="k">Now at</span>
                <x-ui.badge :tone="$visit->stateBadge()">{{ $visit->stateLabel() }}</x-ui.badge>
              </div>

              <div class="tb-gate-next">
                @if(! $visit->isOpen())
                  <span class="tb-gate-block">
                    This visit is finished{{ $visit->wasCancelled() ? ' — it was cancelled' : '' }}.
                  </span>
                @elseif($gate['automatic'])
                  <span class="tb-gate-auto">
                    <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
                    Completes itself once the bill is paid in full.
                  </span>
                  @if($gate['blocker'])<span class="tb-gate-block">{{ $gate['blocker'] }}</span>@endif
                @elseif($gate['ready'])
                  <button type="button" class="btn-tb btn-tb-sm btn-tb-primary"
                          wire:click="advance" wire:loading.attr="disabled" wire:target="advance">
                    <span wire:loading.remove wire:target="advance">
                      {{ $gate['label'] }} <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </span>
                    <span wire:loading wire:target="advance">Moving…</span>
                  </button>
                @elseif($gate['blocker'])
                  {{-- A shut gate says what is shutting it, in figures. A
                       greyed-out button teaches nobody anything. --}}
                  <span class="tb-gate-block">
                    <i class="fas fa-lock" aria-hidden="true"></i> {{ $gate['blocker'] }}
                  </span>
                @endif
              </div>

              <div class="tb-gate-acts">
                {{-- The whole visit as a document, for a patient being sent
                     somewhere else. It opens in a tab rather than
                     downloading, like everything else this system prints
                     (docs/documents.md). --}}
                <a href="{{ route('admin.visits.report', $visit) }}" target="_blank" rel="noopener"
                   class="btn-tb btn-tb-sm btn-tb-ghost">
                  <i class="fas fa-file-medical" aria-hidden="true"></i> Visit report
                </a>
                @if($visit->isOpen())
                  <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost tb-vs-cancelbtn"
                          wire:click="openCancel">
                    <i class="fas fa-ban" aria-hidden="true"></i> Cancel visit
                  </button>
                @endif
                @can('overrideStage', $visit)
                  <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="openStage">
                    <i class="fas fa-sliders" aria-hidden="true"></i> Set state…
                  </button>
                @endcan
              </div>
            </div>
          @endcan
        </div>
      </div>
    @endif
  </x-ui.modal>

  {{-- ── Calling it off ────────────────────────────────────────────────
       One decision, always available while the visit is open, always with a
       reason. Nothing already recorded on the visit is touched. --}}
  <x-ui.modal size="sm" show="showCancel" title="Cancel this visit?">
    @if($showCancel && $visit)
      <form wire:submit="cancelVisit" style="display:contents;">
        <div class="tb-modal-body">
          <p class="tb-small muted tb-mb-4">
            <span class="mono">{{ $visit->visit_no }}</span> — {{ $visit->patient?->full_name }}.
            Everything already recorded on it stays; its open orders keep their charges
            until each one is cancelled in turn.
          </p>
          <x-ui.field label="Why is it being called off?" for="vs-cancel-why" name="cancelNote" required>
            <textarea id="vs-cancel-why" class="tb-textarea" rows="2" maxlength="255"
                      wire:model="cancelNote"
                      placeholder="Patient left · opened in error · duplicate"></textarea>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeCancel">Keep the visit</button>
          <button type="submit" class="btn-tb btn-tb-danger"
                  wire:loading.attr="disabled" wire:target="cancelVisit">
            <span wire:loading.remove wire:target="cancelVisit">
              <i class="fas fa-ban" aria-hidden="true"></i> Cancel visit
            </span>
            <span wire:loading wire:target="cancelVisit">Cancelling…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Putting a visit anywhere ──────────────────────────────────────
       Everything else on this screen is the shape of a normal day. This is
       for the days that are not, so it is gated on its own permission, it
       costs a reason, and it is marked in the trail. --}}
  <x-ui.modal size="md" show="showStage" title="Set this visit's state">
    @if($showStage && $visit)
      <div class="tb-modal-sub">
        <span class="mono">{{ $visit->visit_no }}</span>
        <span class="sep">·</span>
        <span>{{ $visit->patient?->full_name ?? 'Patient' }}</span>
        <x-ui.badge :tone="$visit->stateBadge()">{{ $visit->stateLabel() }}</x-ui.badge>
      </div>

      <form wire:submit="changeState" style="display:contents;">
        <div class="tb-modal-body">
          <div class="tb-alert tb-mb-4" role="status">
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            <span>This skips every check. It goes into the visit's history as a change
                  out of order, with your name on it.</span>
          </div>

          <fieldset class="tb-stages">
            <legend class="tb-label">Status</legend>
            <div class="tb-stage-grid">
              @foreach(\App\Enums\VisitStatus::cases() as $case)
                <label @class(['tb-stage', 'is-on' => $stageStatus === $case->value])
                       wire:key="ss-{{ $case->value }}">
                  <input type="radio" class="sr-only" name="v-status" value="{{ $case->value }}"
                         wire:model.live="stageStatus">
                  <span class="l">{{ $case->label() }}</span>
                </label>
              @endforeach
            </div>
          </fieldset>

          @if($stageStatus === \App\Enums\VisitStatus::Completed->value)
            <fieldset class="tb-stages">
              <legend class="tb-label">Outcome</legend>
              <div class="tb-stage-grid">
                @foreach(\App\Enums\VisitOutcome::cases() as $case)
                  <label @class(['tb-stage', 'is-on' => $stageOutcome === $case->value])
                         wire:key="so-{{ $case->value }}">
                    <input type="radio" class="sr-only" name="v-outcome" value="{{ $case->value }}"
                           wire:model.live="stageOutcome">
                    <span class="l">{{ $case->label() }}</span>
                  </label>
                @endforeach
              </div>
            </fieldset>
          @endif

          <fieldset class="tb-stages">
            <legend class="tb-label">Stage</legend>
            <div class="tb-stage-grid">
              @foreach(\App\Enums\VisitStage::cases() as $case)
                <label @class(['tb-stage', 'is-on' => $stageStage === $case->value])
                       wire:key="sg-{{ $case->value }}">
                  <input type="radio" class="sr-only" name="v-stage" value="{{ $case->value }}"
                         wire:model.live="stageStage">
                  <span class="l">{{ $case->label() }}</span>
                </label>
              @endforeach
            </div>
          </fieldset>
          @error('stageStatus')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
          @error('stageStage')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror

          <x-ui.field label="Why is it being changed out of order?" for="vs-state-why"
                      name="stageNote" required>
            <textarea id="vs-state-why" class="tb-textarea" rows="2" maxlength="255"
                      wire:model="stageNote"
                      placeholder="Finished by mistake · cancelled by the wrong desk · duplicate visit"></textarea>
          </x-ui.field>
        </div>

        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeStage">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-danger"
                  wire:loading.attr="disabled" wire:target="changeState">
            <span wire:loading.remove wire:target="changeState">
              <i class="fas fa-sliders" aria-hidden="true"></i> Set it anyway
            </span>
            <span wire:loading wire:target="changeState">Setting…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
