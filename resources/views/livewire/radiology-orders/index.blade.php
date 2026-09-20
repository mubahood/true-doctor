<div>
  <h1 class="sr-only">Radiology orders</h1>

  @php($targets = 'search,status,outstanding,gotoPage,previousPage,nextPage,perPage,sortBy,clearFilters,advance')
  @php($waiting = $this->waiting)

  {{-- What is still to be read, and how long the oldest has gone unread. --}}
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-inbox" :value="$waiting['ordered']" label="Requested" sub="Not booked in yet" />
    <x-dash.stat icon="fa-calendar-check" :value="$waiting['scheduled']" label="Booked in" sub="Waiting for the room" />
    <x-dash.stat icon="fa-x-ray" :value="$waiting['performed']" label="Awaiting a report"
                 :tone="$waiting['oldest'] !== null && $waiting['oldest'] >= \App\Livewire\RadiologyOrders\Index::OLD_HOURS ? 'warn' : ''"
                 :sub="$waiting['oldest'] === null ? 'Nothing outstanding' : 'Oldest waiting '.$waiting['oldest'].'h'" />
    <x-dash.stat icon="fa-circle-check" tone="ok" :value="$waiting['reported']" label="Reported today" sub="Signed off" />
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
             placeholder="Patient name or number…" aria-label="Search radiology orders by patient">
    </div>

    <select wire:model.live="status" class="tb-select" aria-label="Filter by status">
      <option value="">All statuses</option>
      @foreach($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>

    <label class="tb-check-group" title="Everything that still needs reading">
      <input type="checkbox" wire:model.live="outstanding"> Outstanding only
    </label>

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif

    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Every radiology order</caption>
      <thead><tr>
        <th class="tb-serial">#</th>
        <x-ui.th-sort field="created_at" :sort-field="$sortField" :sort-dir="$sortDir">Requested</x-ui.th-sort>
        <th>Patient</th>
        <th>Studies</th>
        <th>Images</th>
        <x-ui.th-sort field="status" :sort-field="$sortField" :sort-dir="$sortDir">Status</x-ui.th-sort>
        <th><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        @forelse($orders as $o)
          <tr wire:key="rad-{{ $o->id }}">
            <x-ui.serial :rows="$orders" :loop="$loop" />
            <td class="tb-nowrap">
              <button type="button" class="tb-rowbtn" wire:click="openReport({{ $o->id }})" title="Open this order">
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

            <td class="muted">{{ $o->items_count }} {{ \Illuminate\Support\Str::plural('study', $o->items_count) }}</td>

            <td class="muted tb-small">
              @if($o->attachments_count > 0)
                <i class="fas fa-paperclip" aria-hidden="true"></i>
                {{ $o->attachments_count }}
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
              @can('radiology.report')
                <button type="button" class="tb-nextbtn is-record" wire:click="openReport({{ $o->id }})"
                        title="Write the report and attach the images">
                  <i class="fas fa-file-pen" aria-hidden="true"></i> {{ $o->status->isTerminal() ? 'Open report' : 'Report' }}
                </button>
              @endcan

              <x-ui.actions-menu label="Actions for {{ $o->patient?->full_name ?? 'this order' }}">
                <button type="button" role="menuitem" wire:click="openReport({{ $o->id }})">
                  <i class="fas fa-x-ray" aria-hidden="true"></i> Report and images
                </button>
                <a role="menuitem" wire:navigate href="{{ route('admin.radiology-orders.show', $o) }}">
                  <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record
                </a>
                <a role="menuitem" href="{{ route('admin.radiology-orders.pdf', $o) }}" target="_blank" rel="noopener">
                  <i class="fas fa-file-pdf" aria-hidden="true"></i> Printable report
                </a>
                @if($o->visit)
                  <a role="menuitem" wire:navigate href="{{ route('admin.visits.show', $o->visit) }}">
                    <i class="fas fa-stethoscope" aria-hidden="true"></i> The visit it came from
                  </a>
                @endif
                @can('radiology.report')
                  @if($o->status->transitionsTo())
                    <hr>
                    <span class="tb-menu-sec">Move to</span>
                    @foreach($o->status->transitionsTo() as $to)
                      <button type="button" role="menuitem"
                              @class(['danger' => $to === \App\Enums\RadiologyOrderStatus::Cancelled])
                              wire:click="advance({{ $o->id }}, '{{ $to->value }}')">{{ $to->label() }}</button>
                    @endforeach
                  @endif
                @endcan
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="7">
            <x-ui.empty icon="fa-x-ray" noun="radiology orders" :filtered="$this->hasFilters()" wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $orders->links(data: $this->paginationData()) }}

  {{-- ── The report, over the list ─────────────────────────────────
       A radiology module that cannot hold a film is one in name only. --}}
  <x-ui.modal show="showReport" size="xl"
              :title="'Report · '.($this->working?->patient?->full_name ?? 'Radiology order')">
    @if($this->working)
      @php($order = $this->working)
      <div class="tb-modal-body">
        <p class="tb-ending-who">
          {{ $order->created_at->format('D j M · H:i') }}
          @if($order->orderedBy) · asked for by {{ $order->orderedBy->name }} @endif
          @if($order->visit) · <b>{{ $order->visit->visit_no }}</b> @endif
          · <x-ui.badge :tone="$order->status->badge()">{{ $order->status->label() }}</x-ui.badge>
        </p>

        @if($order->items->isNotEmpty())
          <p class="tb-out-none">
            {{ $order->items->pluck('name')->implode(' · ') }}
            @if($order->clinical_notes) — {{ $order->clinical_notes }} @endif
          </p>
        @endif

        <x-ui.field label="Findings" for="rad-findings" name="findings"
                    hint="What the images show, described plainly.">
          <textarea id="rad-findings" wire:model="findings" class="tb-textarea" rows="6"
                    placeholder="Technique, comparison, what is seen…"></textarea>
        </x-ui.field>

        <x-ui.field label="Impression" for="rad-impression" name="impression"
                    hint="The conclusion the referring clinician acts on.">
          <textarea id="rad-impression" wire:model="impression" class="tb-textarea" rows="3"></textarea>
        </x-ui.field>

        @include('livewire.partials.what-was-used', [
            'lead' => 'What the study used',
            'hint' => 'Contrast, film, a repeat exposure — charged to the visit with the study.',
        ])

        <div class="tb-out-sec">
          <span>Images and files</span>
          <span class="tb-out-hint">The films, the printed report, the signed request.</span>
        </div>

        @can('radiology.report')
          <x-ui.drop-file name="files" label="Attach" :has="false" :multiple="true"
                          accept=".pdf,image/*,.txt,.csv,.doc,.docx"
                          idle="Drop the images here, or click to choose"
                          hint="PDF, image, text or document. Up to 10 MB each." />
        @endcan

        @include('livewire.partials.attachment-list', [
            'owner' => $order,
            'route' => 'admin.radiology-orders.attachments.download',
            'mayRemove' => auth()->user()?->can('radiology.report'),
        ])

        @if($order->reportedBy)
          <p class="tb-rad-signed">
            Signed off by {{ $order->reportedBy->name }}
            @if($order->reported_at) · {{ $order->reported_at->format('j M Y · H:i') }} @endif
          </p>
        @endif
      </div>

      <div class="tb-modal-foot tb-out-foot">
        <a class="btn-tb btn-tb-ghost" href="{{ route('admin.radiology-orders.pdf', $order) }}" target="_blank" rel="noopener">
          <i class="fas fa-file-pdf" aria-hidden="true"></i> Printable
        </a>
        <span class="tb-peek-gap"></span>
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeReport">Close</button>
        @can('radiology.report')
          <button type="button" class="btn-tb" wire:click="saveReport" wire:loading.attr="disabled" wire:target="saveReport">
            <span wire:loading.remove wire:target="saveReport"><i class="fas fa-check" aria-hidden="true"></i> Save</span>
            <span wire:loading wire:target="saveReport"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
          @unless($order->status->isTerminal())
            <button type="button" class="btn-tb btn-tb-primary" wire:click="signOff"
                    wire:loading.attr="disabled" wire:target="signOff">
              <span wire:loading.remove wire:target="signOff"><i class="fas fa-signature" aria-hidden="true"></i> Save and sign off</span>
              <span wire:loading wire:target="signOff"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Signing…</span>
            </button>
          @endunless
        @endcan
      </div>
    @endif
  </x-ui.modal>
</div>
