<div>
  <h1 class="sr-only">Lab orders</h1>

  @php($targets = 'search,status,outstanding,gotoPage,previousPage,nextPage,perPage,sortBy,clearFilters,advance')
  @php($waiting = $this->waiting)

  {{-- What the bench owes back. A count of orders is not a workload; "the
       oldest waiting two days" is, and it was nowhere on the page. --}}
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-inbox" :value="$waiting['ordered']" label="Awaiting collection"
                 sub="Requested, no sample yet" />
    <x-dash.stat icon="fa-vial" :value="$waiting['collected']" label="Collected"
                 sub="Sample in, not started" />
    <x-dash.stat icon="fa-flask" :value="$waiting['processing']" label="On the bench"
                 :tone="$waiting['oldest'] !== null && $waiting['oldest'] >= \App\Livewire\LabOrders\Index::OLD_HOURS ? 'warn' : ''"
                 :sub="$waiting['oldest'] === null ? 'Nothing outstanding' : 'Oldest waiting '.$waiting['oldest'].'h'" />
    <x-dash.stat icon="fa-circle-check" tone="ok" :value="$waiting['completed']" label="Reported today"
                 sub="Results handed back" />
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
             placeholder="Patient name or number…" aria-label="Search lab orders by patient">
    </div>

    <select wire:model.live="status" class="tb-select" aria-label="Filter by status">
      <option value="">All statuses</option>
      @foreach($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>

    <label class="tb-check-group" title="Everything that still owes a result">
      <input type="checkbox" wire:model.live="outstanding"> Outstanding only
    </label>

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif

    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Every lab order</caption>
      <thead><tr>
        <th class="tb-serial">#</th>
        <x-ui.th-sort field="created_at" :sort-field="$sortField" :sort-dir="$sortDir">Requested</x-ui.th-sort>
        <th>Patient</th>
        <th>Tests</th>
        <th>Result</th>
        <x-ui.th-sort field="status" :sort-field="$sortField" :sort-dir="$sortDir">Status</x-ui.th-sort>
        <th><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        @forelse($orders as $o)
          <tr wire:key="lab-{{ $o->id }}">
            <x-ui.serial :rows="$orders" :loop="$loop" />
            {{-- How long it has been sitting, not only when it came in: a bench
                 is judged by what has been waiting longest. --}}
            <td class="tb-nowrap">
              <button type="button" class="tb-rowbtn" wire:click="openResult({{ $o->id }})" title="Open this order">
                <span class="tb-when-day">{{ $o->created_at->format('D j M') }}</span>
                <span class="tb-when-time mono">{{ $o->created_at->format('H:i') }}</span>
              </button>
            </td>

            <td class="tb-fw-500">
              @if($o->patient)
                <a class="rowlink" wire:navigate href="{{ route('admin.patients.show', $o->patient) }}">{{ $o->patient->full_name }}</a>
                <span class="tb-row-sub mono">{{ $o->patient->patient_no }}</span>
              @else — @endif
            </td>

            <td class="muted">{{ $o->items_count }} {{ \Illuminate\Support\Str::plural('test', $o->items_count) }}</td>

            {{-- Whether anything has actually come back, which is the question
                 the status word only half answers. --}}
            <td class="muted tb-small">
              @if($o->attachments_count > 0)
                <i class="fas fa-paperclip" aria-hidden="true"></i>
                {{ $o->attachments_count }} {{ \Illuminate\Support\Str::plural('file', $o->attachments_count) }}
              @else
                —
              @endif
            </td>

            <td class="tb-nowrap">
              <x-ui.badge :tone="$o->status->badge()">{{ $o->status->label() }}</x-ui.badge>
              @if($this->isOverdue($o))
                <span class="tb-lab-late">{{ $this->waitedHours($o) }}h</span>
              @endif
            </td>

            <td class="tb-text-right tb-nowrap">
              @can('lab.process')
                <button type="button" class="tb-nextbtn is-record" wire:click="openResult({{ $o->id }})"
                        title="Enter the result and attach what the analyser printed">
                  <i class="fas fa-file-pen" aria-hidden="true"></i> {{ $o->status->isTerminal() ? 'Open result' : 'Enter result' }}
                </button>
              @endcan

              <x-ui.actions-menu label="Actions for {{ $o->patient?->full_name ?? 'this order' }}">
                <button type="button" role="menuitem" wire:click="openResult({{ $o->id }})">
                  <i class="fas fa-flask" aria-hidden="true"></i> Result and files
                </button>
                <a role="menuitem" wire:navigate href="{{ route('admin.lab-orders.show', $o) }}">
                  <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record
                </a>
                <a role="menuitem" href="{{ route('admin.lab-orders.pdf', $o) }}" target="_blank" rel="noopener">
                  <i class="fas fa-file-pdf" aria-hidden="true"></i> Printable result
                </a>
                @if($o->visit)
                  <a role="menuitem" wire:navigate href="{{ route('admin.visits.show', $o->visit) }}">
                    <i class="fas fa-stethoscope" aria-hidden="true"></i> The visit it came from
                  </a>
                @endif
                @can('lab.process')
                  @if($o->status->transitionsTo())
                    <hr>
                    <span class="tb-menu-sec">Move to</span>
                    @foreach($o->status->transitionsTo() as $to)
                      <button type="button" role="menuitem"
                              @class(['danger' => $to === \App\Enums\LabOrderStatus::Cancelled])
                              wire:click="advance({{ $o->id }}, '{{ $to->value }}')">{{ $to->label() }}</button>
                    @endforeach
                  @endif
                @endcan
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="7">
            <x-ui.empty icon="fa-flask" noun="lab orders" :filtered="$this->hasFilters()" wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $orders->links(data: $this->paginationData()) }}

  {{-- ── The result, over the list ─────────────────────────────────
       Entering one used to be two page loads away, and what the analyser
       printed had nowhere to go at all. --}}
  <x-ui.modal show="showResult" size="xl"
              :title="'Result · '.($this->working?->patient?->full_name ?? 'Lab order')">
    @if($this->working)
      @php($order = $this->working)
      <div class="tb-modal-body">
        <p class="tb-ending-who">
          {{ $order->created_at->format('D j M · H:i') }}
          @if($order->orderedBy) · asked for by {{ $order->orderedBy->name }} @endif
          @if($order->visit) · <b>{{ $order->visit->visit_no }}</b> @endif
          · <x-ui.badge :tone="$order->status->badge()">{{ $order->status->label() }}</x-ui.badge>
        </p>

        @if($order->clinical_notes)
          <p class="tb-out-none">{{ $order->clinical_notes }}</p>
        @endif

        <div class="tb-out-sec"><span>What came back</span></div>

        <table class="tb-table tb-out-lines">
          <caption class="sr-only">Results for each test on this order</caption>
          <thead><tr>
            <th>Test</th>
            <th>Value</th>
            <th>Flag</th>
            <th>Note</th>
          </tr></thead>
          <tbody>
            @foreach($order->items as $item)
              <tr wire:key="lab-item-{{ $item->id }}">
                <td class="tb-fw-500">
                  {{ $item->name }}
                  @if($item->reference_range)<span class="tb-row-sub">Normal {{ $item->reference_range }}</span>@endif
                </td>
                <td>
                  <input type="text" class="tb-input" maxlength="120"
                         wire:model="results.{{ $item->id }}.result_value"
                         aria-label="Result for {{ $item->name }}">
                  @error('results.'.$item->id.'.result_value')<div class="tb-field-error">{{ $message }}</div>@enderror
                </td>
                <td>
                  <select class="tb-select" wire:model="results.{{ $item->id }}.result_flag"
                          aria-label="Flag for {{ $item->name }}">
                    <option value="">Normal</option>
                    @foreach($this->flags() as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                  </select>
                </td>
                <td>
                  <input type="text" class="tb-input" maxlength="255"
                         wire:model="results.{{ $item->id }}.result_notes"
                         aria-label="Note for {{ $item->name }}">
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>

        @include('livewire.partials.what-was-used', [
            'lead' => 'What the test used',
            'hint' => 'Reagents, tubes, a repeat run — charged to the visit with the tests.',
        ])

        {{-- The other half of the record: what the analyser printed. --}}
        <div class="tb-out-sec">
          <span>Files</span>
          <span class="tb-out-hint">The report the machine produced, a photograph of the slide, the signed request.</span>
        </div>

        @can('lab.process')
          <x-ui.drop-file name="files" label="Attach" :has="false" :multiple="true"
                          accept=".pdf,image/*,.txt,.csv,.doc,.docx"
                          idle="Drop the result here, or click to choose"
                          hint="PDF, image, text or document. Up to 10 MB each." />
        @endcan

        @include('livewire.partials.attachment-list', [
            'owner' => $order,
            'route' => 'admin.lab-orders.attachments.download',
            'mayRemove' => auth()->user()?->can('lab.process'),
        ])
      </div>

      <div class="tb-modal-foot tb-out-foot">
        <a class="btn-tb btn-tb-ghost" href="{{ route('admin.lab-orders.pdf', $order) }}" target="_blank" rel="noopener">
          <i class="fas fa-file-pdf" aria-hidden="true"></i> Printable
        </a>
        <span class="tb-peek-gap"></span>
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeResult">Close</button>
        @can('lab.process')
          <button type="button" class="btn-tb" wire:click="saveResults" wire:loading.attr="disabled" wire:target="saveResults">
            <span wire:loading.remove wire:target="saveResults"><i class="fas fa-check" aria-hidden="true"></i> Save</span>
            <span wire:loading wire:target="saveResults"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
          @unless($order->status->isTerminal())
            <button type="button" class="btn-tb btn-tb-primary" wire:click="completeOrder"
                    wire:loading.attr="disabled" wire:target="completeOrder">
              <span wire:loading.remove wire:target="completeOrder"><i class="fas fa-paper-plane" aria-hidden="true"></i> Save and report back</span>
              <span wire:loading wire:target="completeOrder"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Reporting…</span>
            </button>
          @endunless
        @endcan
      </div>
    @endif
  </x-ui.modal>
</div>
