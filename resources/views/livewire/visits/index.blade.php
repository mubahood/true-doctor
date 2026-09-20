<div>
  <h1 class="sr-only">Visits</h1>

  @php($targets = 'search,gotoPage,previousPage,nextPage,perPage,status,stage,sortBy')

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Patient or visit no.…" aria-label="Search visits">
    </div>
    {{-- One select per question the record answers: is it alive, and what is
         being done to it. Filtering on both at once is the common case —
         "ongoing visits sitting at billing" — which a single combined list of
         six words cannot express. --}}
    <select wire:model.live="status" class="tb-select" aria-label="Filter by status">
      <option value="">All statuses</option>
      @foreach($this->statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>
    <select wire:model.live="stage" class="tb-select" aria-label="Filter by stage">
      <option value="">All stages</option>
      @foreach($this->stages as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>
    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Visit::class)
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New visit</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap">
    <table class="tb-table">
      <thead>
        <tr>
          <th class="tb-serial">#</th><th wire:click="sortBy('visit_no')" class="sortable">Visit @include('livewire.partials.sort-caret', ['field' => 'visit_no'])</th>
          <th>Patient</th>
          <th>Doctor</th>
          <th wire:click="sortBy('status')" class="sortable">Status @include('livewire.partials.sort-caret', ['field' => 'status'])</th>
          <th wire:click="sortBy('stage')" class="sortable">Stage @include('livewire.partials.sort-caret', ['field' => 'stage'])</th>
          <th class="tb-text-right">Orders</th>
          {{-- The money, in the order it is asked about: what the visit comes
               to, and what is still owed on it. Neither sorts: a total is a
               live figure on an uninvoiced visit and a frozen one afterwards,
               and the database cannot order a column that is half of each. --}}
          <th class="tb-text-right">Total</th>
          <th class="tb-text-right">Balance</th>
          <th wire:click="sortBy('created_at')" class="sortable">Opened @include('livewire.partials.sort-caret', ['field' => 'created_at'])</th>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        @forelse($rows as $c)
          <tr wire:key="cons-{{ $c->id }}">
            <x-ui.serial :rows="$rows" :loop="$loop" />
            <td class="mono"><button type="button" class="tb-rowbtn"
                    wire:click="$dispatch('visit-action', { visitId: {{ $c->id }}, section: 'summary' })"
                    title="Open {{ $c->visit_no }}">{{ $c->visit_no }}</button></td>
            <td class="tb-fw-500">
              @if($c->patient)
                <a class="rowlink" wire:navigate href="{{ route('admin.patients.show', $c->patient) }}"
                   title="Open {{ $c->patient->full_name }}’s record">{{ $c->patient->full_name }}</a>
              @else
                —
              @endif
            </td>
            <td class="muted">{{ $c->doctor?->name ?? '—' }}</td>

            {{-- Is it alive, and — once it is over — how it ended. --}}
            <td>
              @if($c->outcome)
                <x-ui.badge :tone="$c->outcome->badge()">{{ $c->outcome->label() }}</x-ui.badge>
              @else
                <x-ui.badge :tone="$c->status->badge()">{{ $c->status->label() }}</x-ui.badge>
              @endif
            </td>

            {{-- And what is being done to it. A visit nobody has started is
                 not AT a stage yet, and a finished one is no longer moving,
                 so neither is shown as though it were live. --}}
            <td>
              @if($c->status === \App\Enums\VisitStatus::Pending)
                <span class="muted tb-small">Not started</span>
              @elseif($c->isOpen())
                <x-ui.badge :tone="$c->stage->badge()">{{ $c->stage->label() }}</x-ui.badge>
              @else
                <span class="muted tb-small">{{ $c->stage->label() }}</span>
              @endif
            </td>

            <td class="tb-text-right">
              @if($c->orders_count)
                <span class="tb-count" @class(['is-open' => $c->open_orders_count])
                      title="{{ $c->open_orders_count ? $c->open_orders_count.' still open' : 'all finished' }}">
                  {{ $c->orders_count }}
                </span>
              @else
                <span class="muted">—</span>
              @endif
            </td>

            {{-- What the work comes to. Nothing charged yet reads as a dash
                 rather than as a zero: a visit with no orders on it has not
                 been billed nothing, it has not been billed. --}}
            <td class="tb-text-right tb-nowrap mono">
              @if(bccomp((string) $c->bill_total, '0', 2) > 0)
                {{ \App\Support\HospitalSettings::money($c->bill_total) }}
              @else
                <span class="muted">—</span>
              @endif
            </td>

            {{-- And what is still to pay. A balance exists only once there is
                 an invoice carrying it; before that the column says so. --}}
            <td class="tb-text-right tb-nowrap mono">
              @if($c->bill_balance === null)
                <span class="muted" title="Not invoiced yet">—</span>
              @elseif(bccomp((string) $c->bill_balance, '0', 2) > 0)
                <span class="tb-danger">{{ \App\Support\HospitalSettings::money($c->bill_balance) }}</span>
              @else
                <x-ui.badge tone="success">Paid</x-ui.badge>
              @endif
            </td>

            <td class="tb-nowrap muted">{{ $c->created_at->format('d M H:i') }}</td>
            <td class="tb-text-right tb-nowrap tb-row-actions">
              <x-ui.icon-button label="Open visit {{ $c->visit_no }}" icon="fa-eye"
                                wire:click="$dispatch('visit-action', { visitId: {{ $c->id }}, section: 'summary' })" />
              {{-- Nine entries under four headings was a wall. A row menu is
                   for the two or three things anyone does from a list; the
                   rest of the visit is one click away inside it. No headings,
                   no counts, one rule: nothing here that the click would
                   refuse. --}}
              <x-ui.actions-menu label="Actions for visit {{ $c->visit_no }}">
                <button type="button" role="menuitem"
                        wire:click="$dispatch('visit-action', { visitId: {{ $c->id }}, section: 'summary' })">
                  <i class="fas fa-eye" aria-hidden="true"></i> Open visit
                </button>

                {{-- The whole record, as a document to hand over. --}}
                <a role="menuitem" href="{{ route('admin.visits.report', $c) }}" target="_blank" rel="noopener">
                  <i class="fas fa-file-medical" aria-hidden="true"></i> Visit report (PDF)
                </a>

                @if($c->isOpen())
                  @canany(['visits.create', 'visits.manage'])
                    <button type="button" role="menuitem"
                            wire:click="$dispatch('visit-action', { visitId: {{ $c->id }}, section: 'orders', add: true })">
                      <i class="fas fa-plus" aria-hidden="true"></i> Add an order
                    </button>
                  @endcanany

                  @can('manage', $c)
                    {{-- Only when its gate is already open, worked out from
                         counts the listing query fetched. --}}
                    @if($c->gateIsOpen())
                      <button type="button" role="menuitem" wire:click="advanceVisit({{ $c->id }})">
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        {{ $c->stage->advanceLabel() }}
                      </button>
                    @endif
                    <button type="button" role="menuitem"
                            wire:click="$dispatch('visit-cancel', { visitId: {{ $c->id }} })">
                      <i class="fas fa-ban" aria-hidden="true"></i> Cancel visit
                    </button>
                  @endcan
                @endif

                <hr>
                @canany(['billing.view', 'billing.manage'])
                  <button type="button" role="menuitem"
                          wire:click="$dispatch('visit-action', { visitId: {{ $c->id }}, section: 'bill' })">
                    <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> Bill &amp; payments
                  </button>
                @endcanany
                <a role="menuitem" wire:navigate href="{{ route('admin.visits.show', $c) }}">
                  <i class="fas fa-folder-open" aria-hidden="true"></i> Full page
                </a>
                {{-- The patient's record used to sit here and does not belong:
                     this menu is for things done to THIS VISIT, and the rule
                     above is the two or three anyone does from a list. Their
                     name in the row is the link now, which is where somebody
                     reaches for it anyway. --}}

                @can('overrideStage', $c)
                  <hr>
                  <button type="button" role="menuitem"
                          wire:click="$dispatch('visit-stage', { visitId: {{ $c->id }} })">
                    <i class="fas fa-sliders" aria-hidden="true"></i> Set state…
                  </button>
                @endcan
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="11"><x-ui.empty icon="fa-stethoscope" noun="visits"
              :filtered="$search !== '' || $status !== '' || $stage !== ''"
              wire:click="$set('search', '')" /></td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- Every row action opens here. Mounted once for the whole page: the modal
       mounts the detail page's own panel for whichever action was asked for. --}}
  <livewire:visits.actions />

  {{-- ── Slide-over: open visit (existing patient · new patient intake) ── --}}
  <x-ui.modal size="xl" show="showForm" title="New visit">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">

      {{-- Opening a visit registers a patient, claims an appointment slot and
           allocates a number off a locked day sequence. It gets a real pause,
           not a spinner in a button. --}}
      <div class="tb-working" wire:loading.flex wire:target="save" role="status" aria-live="polite">
        <div class="tb-working-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
        <p class="tb-working-head">{{ $tab === 'intake' ? 'Registering and opening…' : 'Opening the visit…' }}</p>
        <p class="tb-working-sub">Allocating the number and starting the record.</p>
      </div>

      <div class="tb-modal-body tb-vform">

        {{-- ── Who ──────────────────────────────────────────────────── --}}
        <section class="tb-vform-step">
          <h4 class="tb-step-h">Who is it?</h4>

          <fieldset class="tb-kinds tb-kinds-2">
            <legend class="sr-only">Is the patient already registered?</legend>
            <div class="tb-kind-grid">
              <label @class(['tb-kind', 'is-on' => $tab === 'existing'])>
                <input type="radio" class="sr-only" name="visit-source" value="existing"
                       wire:click="setTab('existing')" @checked($tab === 'existing')>
                <i class="fas fa-user" aria-hidden="true"></i>
                <span>Registered patient</span>
              </label>
              <label @class(['tb-kind', 'is-on' => $tab === 'intake'])>
                <input type="radio" class="sr-only" name="visit-source" value="intake"
                       wire:click="setTab('intake')" @checked($tab === 'intake')>
                <i class="fas fa-user-plus" aria-hidden="true"></i>
                <span>New patient</span>
              </label>
            </div>
          </fieldset>

          @if($tab === 'existing')
            <x-ui.field label="Patient" name="patient_id" required>
              <livewire:ui.select-search resource="patients" name="patient_id" :selected="$patient_id"
                placeholder="Search by name, number or phone…"
                :key="'v-patient-'.$formNonce" />
            </x-ui.field>

            {{-- What the desk should see the moment the patient is chosen,
                 rather than discover after the visit is open. --}}
            @if($this->patientBrief)
              @php($brief = $this->patientBrief)
              <div class="tb-brief">
                <div class="tb-brief-who">
                  <b>{{ $brief['name'] }}</b>
                  <span class="no">{{ $brief['patient_no'] }}</span>
                  @if($brief['meta'])<span class="meta">{{ $brief['meta'] }}</span>@endif
                </div>

                @if($brief['open_visit'])
                  <div class="tb-brief-row is-warn">
                    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                    <span>
                      Already has an open visit — <b>{{ $brief['open_visit']['visit_no'] }}</b>
                      ({{ $brief['open_visit']['status'] }}). Opening another splits their bill in two.
                    </span>
                    <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="openExisting">
                      Go to it
                    </button>
                  </div>
                @endif

                @if($brief['appointment'])
                  <div class="tb-brief-row is-info">
                    <i class="fas fa-calendar-check" aria-hidden="true"></i>
                    <span>
                      Booked today at <b>{{ $brief['appointment']['at'] }}</b>
                      @if($brief['appointment']['doctor']) with {{ $brief['appointment']['doctor'] }}@endif.
                    </span>
                    @if($appointment_id === null)
                      <button type="button" class="btn-tb btn-tb-sm btn-tb-primary" wire:click="linkAppointment">
                        This visit fulfils it
                      </button>
                    @else
                      <span class="tb-brief-done"><i class="fas fa-check" aria-hidden="true"></i> Linked</span>
                      <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="unlinkAppointment">Undo</button>
                    @endif
                  </div>
                @endif

                @if(bccomp($brief['owed'], '0.00', 2) > 0)
                  <div class="tb-brief-row is-due">
                    <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>
                    <span>Outstanding balance <b><x-ui.money :amount="$brief['owed']" /></b> from earlier visits.</span>
                  </div>
                @endif
              </div>
            @endif
          @else
            <div class="tb-form-grid">
              <x-ui.field label="First name" for="v-first" name="first_name" required>
                <input id="v-first" type="text" wire:model="first_name" class="tb-input" required>
              </x-ui.field>
              <x-ui.field label="Last name" for="v-last" name="last_name" required>
                <input id="v-last" type="text" wire:model="last_name" class="tb-input" required>
              </x-ui.field>
            </div>

            <x-ui.field label="Sex" name="sex">
              <div class="tb-kind-grid tb-kind-grid-sex">
                @foreach($this->sexes as $val => $label)
                  <label @class(['tb-kind', 'is-on' => $sex === $val]) wire:key="sx-{{ $val }}">
                    <input type="radio" class="sr-only" name="v-sex" value="{{ $val }}" wire:model.live="sex">
                    <span>{{ $label }}</span>
                  </label>
                @endforeach
              </div>
            </x-ui.field>

            <div class="tb-form-grid">
              <x-ui.field label="Date of birth" for="v-dob" name="dob">
                <input id="v-dob" type="date" wire:model="dob" class="tb-input" max="{{ now()->toDateString() }}">
              </x-ui.field>
              <x-ui.field label="Phone" for="v-phone" name="phone_1">
                <input id="v-phone" type="tel" wire:model="phone_1" class="tb-input" placeholder="07…">
              </x-ui.field>
            </div>

            <label class="tb-check-group">
              <input type="checkbox" wire:model="consent_given"> Consent given
            </label>
          @endif
        </section>

        {{-- ── How are they? ──────────────────────────────────────────
             This was "Where is it going?" — a department and a doctor. Neither
             belongs to a visit: the ORDERS raised on it carry the department
             that does the work, and the doctor is recorded with the clinical
             notes by the person who actually sees them. Reception opens a visit
             before either is known.

             What reception DOES have is the patient in front of them, so this
             is where their vitals go.

             NO SUGGESTION PILLS ON A VITAL, deliberately. Everywhere else in
             this system a pill saves typing; here it would put a number nobody
             measured into a clinical record. A reading is taken or it is left
             empty. --}}
        <section class="tb-vform-step">
          <h4 class="tb-step-h">How are they?<span class="tb-step-note">Leave a reading empty if it was not taken</span></h4>

          <div class="tb-vitals-grid">
            <x-ui.field label="Temp °C" for="v-temp" name="temperature">
              <input id="v-temp" type="number" step="0.1" min="25" max="45" wire:model="temperature"
                     class="tb-input" inputmode="decimal" placeholder="36.5">
            </x-ui.field>
            <x-ui.field label="BP mmHg" for="v-bp" name="blood_pressure">
              <input id="v-bp" type="text" wire:model="blood_pressure" class="tb-input"
                     maxlength="12" placeholder="120/80">
            </x-ui.field>
            <x-ui.field label="Pulse bpm" for="v-pulse" name="pulse">
              <input id="v-pulse" type="number" min="20" max="300" wire:model="pulse"
                     class="tb-input" inputmode="numeric" placeholder="72">
            </x-ui.field>
            <x-ui.field label="Resp /min" for="v-rr" name="respiratory_rate">
              <input id="v-rr" type="number" min="4" max="80" wire:model="respiratory_rate"
                     class="tb-input" inputmode="numeric" placeholder="16">
            </x-ui.field>
            <x-ui.field label="SpO₂ %" for="v-spo2" name="spo2">
              <input id="v-spo2" type="number" min="50" max="100" wire:model="spo2"
                     class="tb-input" inputmode="numeric" placeholder="98">
            </x-ui.field>
            <x-ui.field label="Weight kg" for="v-weight" name="weight">
              <input id="v-weight" type="number" step="0.1" min="0.5" max="500" wire:model.live.debounce.500ms="weight"
                     class="tb-input" inputmode="decimal">
            </x-ui.field>
            <x-ui.field label="Height cm" for="v-height" name="height">
              <input id="v-height" type="number" step="0.1" min="20" max="260" wire:model.live.debounce.500ms="height"
                     class="tb-input" inputmode="decimal">
            </x-ui.field>
            <x-ui.field label="BMI" name="bmi">
              <input type="text" class="tb-input" disabled title="Worked out from weight and height"
                     value="{{ $this->bmiPreview ?? '—' }}">
            </x-ui.field>
          </div>
        </section>

        {{-- ── Why have they come? ────────────────────────────────────── --}}
        <section class="tb-vform-step">
          <h4 class="tb-step-h">Why have they come?</h4>

          <x-ui.field label="Reason for visit" for="v-reason" name="reason">
            <input id="v-reason" type="text" wire:model="reason" class="tb-input" maxlength="255"
                   placeholder="{{ $this->reasonSuggestions[0] ?? 'New complaint' }}">

            {{-- How this hospital writes it, its own words first. A click adds
                 it to what is there and a second click takes it out again —
                 somebody comes in for a follow-up AND a medication refill, and
                 the second pill used to wipe the first. --}}
            @if($this->reasonSuggestions)
              <div class="tb-picker-pills">
                @foreach($this->reasonSuggestions as $phrase)
                  <button type="button" @class(['tb-pill', 'is-on' => $this->hasPhrase('reason', $phrase)])
                          wire:key="rs-{{ md5($phrase) }}" wire:click="usePhrase('reason', @js($phrase))">
                    {{ $phrase }}
                  </button>
                @endforeach
              </div>
            @endif
          </x-ui.field>

          <x-ui.field label="Presenting complaints" for="v-complaints" name="complaints">
            <textarea id="v-complaints" wire:model="complaints" class="tb-textarea" rows="2" maxlength="2000"
                      placeholder="What the patient says is wrong, in their words"></textarea>
            <div class="tb-picker-pills">
              @foreach($this->clinicalPhrases['complaints'] as $phrase)
                <button type="button" @class(['tb-pill', 'is-on' => $this->hasPhrase('complaints', $phrase)])
                        wire:key="cp-{{ md5($phrase) }}" wire:click="usePhrase('complaints', @js($phrase))">
                  {{ $phrase }}
                </button>
              @endforeach
            </div>
          </x-ui.field>

          {{-- A diagnosis at the desk is unusual but not wrong: a walk-in seen
               by the clinician registering them arrives with one already. It
               stays optional, and the notes panel is still where it is written
               properly. --}}
          <x-ui.field label="Diagnosis" for="v-diagnosis" name="diagnosis"
                      hint="If it is already known.">
            <textarea id="v-diagnosis" wire:model="diagnosis" class="tb-textarea" rows="2" maxlength="2000"></textarea>
            <div class="tb-picker-pills">
              @foreach($this->clinicalPhrases['diagnosis'] as $phrase)
                <button type="button" @class(['tb-pill', 'is-on' => $this->hasPhrase('diagnosis', $phrase)])
                        wire:key="dx-{{ md5($phrase) }}" wire:click="usePhrase('diagnosis', @js($phrase))">
                  {{ $phrase }}
                </button>
              @endforeach
            </div>
          </x-ui.field>

          {{-- Two states, and only two: a visit is registered and waiting, or
               somebody has begun. Everything after Ongoing is reached by
               finishing the work, never by choosing it here (docs/visits.md). --}}
          <x-ui.field label="Is anybody seeing them yet?" name="start_now">
            <div class="tb-seg-cards" role="radiogroup" aria-label="Visit status">
              <label @class(['tb-seg-opt', 'is-on' => ! $start_now])>
                <input type="radio" wire:model.live="start_now" value="0" class="sr-only">
                <span>Pending</span><em>Registered, waiting</em>
              </label>
              <label @class(['tb-seg-opt', 'is-on' => $start_now])>
                <input type="radio" wire:model.live="start_now" value="1" class="sr-only">
                <span>Ongoing</span><em>Being seen now</em>
              </label>
            </div>
          </x-ui.field>
        </section>
      </div>

      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save">
            <i class="fas fa-check" aria-hidden="true"></i>
            {{ $tab === 'intake' ? 'Register & create visit' : 'Create visit' }}
          </span>
          <span wire:loading wire:target="save">Opening…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>
</div>
