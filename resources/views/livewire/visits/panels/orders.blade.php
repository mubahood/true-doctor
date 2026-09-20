<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-list-check" aria-hidden="true"></i> Orders</span>
    {{-- Not offered on a finished visit: work raised on a cancelled one bills
         a patient for an attendance the hospital decided did not happen. --}}
    @if($this->writable)
      <button type="button" class="btn-tb btn-tb-sm btn-tb-primary" wire:click="openAdd">
        <i class="fas fa-plus" aria-hidden="true"></i> Add order
      </button>
    @endif
  </div>

  <div class="tb-card-body">
    {{-- A lens on one list, never a different list. --}}
    <div class="tb-ord-filters" role="group" aria-label="Filter orders by type">
      <button type="button" @class(['tb-chip', 'is-on' => $filter === ''])
              wire:click="$set('filter', '')">
        All <b>{{ $this->counts[''] }}</b>
      </button>
      @foreach(\App\Enums\OrderType::cases() as $case)
        <button type="button" @class(['tb-chip', 'is-on' => $filter === $case->value])
                wire:click="$set('filter', '{{ $case->value }}')">
          <i class="fas {{ $case->icon() }}" aria-hidden="true"></i>
          {{ $case->label() }} <b @class(['is-zero' => $this->counts[$case->value] === 0])>{{ $this->counts[$case->value] }}</b>
        </button>
      @endforeach
    </div>

    {{-- A row apiece: type, what, who, when, where it stands, what it cost.
         The detail — notes, every item, the trail — is a click away rather than
         printed for every order whether or not anyone is reading it. --}}
    @if($this->orders->isEmpty())
      <x-ui.empty compact icon="fa-list-check"
                  message="{{ $filter === '' ? 'Nothing has been ordered on this visit.' : 'Nothing of that kind on this visit.' }}" />
    @else
      <div class="tb-table-wrap">
        <table class="tb-table tb-ord-table">
          <caption class="sr-only">Orders on this visit</caption>
          <thead>
            <tr>
              <th>Order</th>
              <th>Assigned to</th>
              <th>Raised</th>
              <th>Status</th>
              <th class="tb-text-right">Cost</th>
              <th><span class="sr-only">Manage</span></th>
            </tr>
          </thead>
          <tbody>
            @foreach($this->orders as $order)
              <tr wire:key="ord-{{ $order->id }}" class="tb-ord-row" wire:click="view({{ $order->id }})"
                  tabindex="0" wire:keydown.enter="view({{ $order->id }})" role="button"
                  aria-label="Manage {{ $order->title }}">
                <td>
                  <span class="tb-ord-t"><i class="fas {{ $order->type->icon() }}" aria-hidden="true"></i>{{ $order->title }}</span>
                  <span class="tb-ord-k">{{ $order->type->label() }}@if($order->items->count() > 1) · {{ $order->items->count() }} items @endif</span>
                </td>
                <td class="muted">{{ $order->assignee?->name ?? $order->department?->name ?? 'Unassigned' }}</td>
                <td class="muted tb-nowrap">{{ $order->created_at->format('d M · H:i') }}</td>
                <td><x-ui.badge :tone="$order->status->badge()">{{ $order->status->label() }}</x-ui.badge></td>
                <td class="tb-text-right tb-nowrap">
                  @if($order->items->isNotEmpty())
                    <x-ui.money :amount="$order->items->where('status', '!=', \App\Enums\OrderItemStatus::Cancelled)->sum('line_total')" />
                  @else
                    <span class="muted">—</span>
                  @endif
                </td>
                <td class="tb-text-right"><i class="fas fa-chevron-right tb-ord-go" aria-hidden="true"></i></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  {{-- ── Placing one ────────────────────────────────────────────── --}}
  <x-ui.modal size="lg" show="showAdd" title="{{ $placed !== null ? 'Done' : 'Add an order' }}">
    @if($showAdd && $placed !== null)

      {{-- Done. Ordering rarely comes one at a time — the doctor who sends
           bloods often sends a scan in the same breath — so the dialog says
           what it did and offers the two ways out, rather than closing and
           making the reader find the button again. Nothing is half-typed any
           more, so Escape and the backdrop close without asking to discard. --}}
      <div class="tb-modal-body tb-done" x-init="delete $root.dataset.tdDirty">
        <div class="tb-done-mark"><i class="fas fa-check" aria-hidden="true"></i></div>
        <p class="tb-done-head">{{ $placed }}</p>
        <p class="tb-done-sub">Anything else for this patient while you are here?</p>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeAdd">Done for now</button>
        {{-- Focus lands here so a second order is Enter away, and so the
             keyboard is not left stranded on the button that just vanished. --}}
        <button type="button" class="btn-tb btn-tb-primary" wire:click="addAnother"
                x-init="$nextTick(() => $el.focus())">
          <i class="fas fa-plus" aria-hidden="true"></i> Add another
        </button>
      </div>

    @elseif($showAdd)
      <form wire:submit="add" style="display:contents;">

        {{-- The wait. Placing an order can move stock, raise a lab order and
             put a line on the bill in one go, so it gets a real pause rather
             than a spinner in a button: the form goes quiet behind a veil
             while the squares fill, and nothing below it can be touched. --}}
        <div class="tb-working" wire:loading.flex wire:target="add" role="status" aria-live="polite">
          <div class="tb-working-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
          <p class="tb-working-head">
            {{ $type === \App\Enums\OrderType::Admission->value ? 'Admitting…' : 'Placing the order…' }}
          </p>
          <p class="tb-working-sub">
            {{ $type === \App\Enums\OrderType::Admission->value
                ? 'Taking the bed and opening the stay.'
                : 'Recording the work and its charge together.' }}
          </p>
        </div>

        <div class="tb-modal-body">

          {{-- The kind of work, as things to choose between rather than a
               list to unfold. Six options with icons read faster than a
               closed <select>, and the choice drives everything below it. --}}
          <fieldset class="tb-kinds">
            <legend class="tb-label">What kind of work?</legend>
            <div class="tb-kind-grid">
              @foreach(\App\Enums\OrderType::cases() as $case)
                <label @class(['tb-kind', 'is-on' => $type === $case->value]) wire:key="k-{{ $case->value }}">
                  <input type="radio" class="sr-only" name="order-type"
                         value="{{ $case->value }}" wire:model.live="type">
                  <i class="fas {{ $case->icon() }}" aria-hidden="true"></i>
                  <span>{{ $case->label() }}</span>
                </label>
              @endforeach
            </div>
            @error('type')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
          </fieldset>

          {{-- What the chosen kind needs. One block whichever it is, so the
               dialog does not jump as the choice changes. --}}
          <div class="tb-kind-body" wire:key="body-{{ $type }}">
            @if(in_array($type, [\App\Enums\OrderType::Lab->value, \App\Enums\OrderType::Imaging->value], true))
              @php($isLab = $type === \App\Enums\OrderType::Lab->value)
              <x-ui.field :label="$isLab ? 'Which tests?' : 'Which studies?'" for="ord-pick"
                          :name="$isLab ? 'test_ids' : 'study_ids'" required>
                <div class="tb-search-wrap tb-mb-4">
                  <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                  <input id="ord-pick" type="search" class="tb-input"
                         wire:model.live.debounce.300ms="pick"
                         placeholder="{{ $isLab ? 'Search tests…' : 'Search studies…' }}">
                </div>
                <div class="tb-pick">
                  @forelse($this->catalogue as $row)
                    <label wire:key="pk-{{ $row['id'] }}">
                      <input type="checkbox" value="{{ $row['id'] }}"
                             wire:model="{{ $isLab ? 'test_ids' : 'study_ids' }}">
                      <span>{{ $row['name'] }}</span>
                      <b><x-ui.money :amount="$row['price']" /></b>
                    </label>
                  @empty
                    <span class="muted tb-small">
                      {{ $pick !== '' ? 'Nothing matches that.' : ($isLab ? 'No lab tests in the catalogue yet.' : 'No studies in the catalogue yet.') }}
                    </span>
                  @endforelse
                </div>
                @if($this->catalogueTotal > count($this->catalogue))
                  <p class="tb-pick-note">Showing {{ count($this->catalogue) }} of {{ $this->catalogueTotal }} — type to narrow.</p>
                @endif
              </x-ui.field>

            @else
              <x-ui.field label="What is it?" for="ord-title" name="title" required>
                <input id="ord-title" type="text" class="tb-input" maxlength="160" required
                       placeholder="{{ $this->titleSuggestions[0] ?? 'See Dr Alice · Wound dressing' }}"
                       wire:model="title">

                {{-- How this kind of work is usually written down here. What
                     the hospital actually writes comes first; the curated set
                     tops it up. One click fills the box. --}}
                @if($this->titleSuggestions)
                  <div class="tb-picker-pills">
                    @foreach($this->titleSuggestions as $phrase)
                      <button type="button" @class(['tb-pill', 'is-on' => $title === $phrase])
                              wire:key="ts-{{ $type }}-{{ md5($phrase) }}"
                              wire:click="useTitle(@js($phrase))">
                        {{ $phrase }}
                      </button>
                    @endforeach
                  </div>
                @endif
              </x-ui.field>
            @endif
          </div>

          {{-- Where the patient is going. A bed is scarce and exclusive, so it
               is claimed when the work is raised — the ward narrows the bed
               box, exactly as a department narrows the doctor box. --}}
          @if($type === \App\Enums\OrderType::Admission->value)
            <div class="tb-grid-2">
              <x-ui.field label="Ward" name="ward_id">
                <livewire:ui.select-search resource="wards" name="ward_id"
                                           :selected="$ward_id" placeholder="Search wards…"
                                           :key="'pick-ward-'.$visitId.'-'.$formNonce" />
              </x-ui.field>
              <x-ui.field label="Bed" name="bed_id" required
                          :hint="$ward_id ? 'Free beds in this ward' : 'Free beds across the hospital'">
                <livewire:ui.select-search resource="beds-available" name="bed_id"
                                           :selected="$bed_id" :scope="$ward_id"
                                           placeholder="Search free beds…"
                                           :key="'pick-bed-'.$visitId.'-'.$formNonce.'-'.($ward_id ?? 0)" />
              </x-ui.field>
            </div>
          @endif

          {{-- Who and where — searched, never a list of everyone. --}}
          <div class="tb-grid-2">
            <x-ui.field :label="$type === \App\Enums\OrderType::Admission->value ? 'Admitting doctor' : 'Assign to'"
                        for="ord-who" name="assigned_to">
              <livewire:ui.select-search resource="staff" name="assigned_to"
                                         :selected="$assigned_to" placeholder="Search staff…"
                                         :key="'pick-who-'.$visitId.'-'.$formNonce" />
            </x-ui.field>
            <x-ui.field label="Department" for="ord-dept" name="department_id">
              <livewire:ui.select-search resource="departments" name="department_id"
                                         :selected="$department_id" placeholder="Search departments…"
                                         :key="'pick-dept-'.$visitId.'-'.$formNonce" />
            </x-ui.field>
          </div>

          <x-ui.field
            label="{{ in_array($type, [\App\Enums\OrderType::Lab->value, \App\Enums\OrderType::Imaging->value], true) ? 'Clinical notes' : 'Instructions' }}"
            for="ord-notes" name="notes">
            <textarea id="ord-notes" class="tb-textarea" rows="3" maxlength="2000"
                      placeholder="Anything the person doing this needs to know"
                      wire:model="notes"></textarea>
          </x-ui.field>
        </div>

        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showAdd', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="add">
            <span wire:loading.remove wire:target="add">
              <i class="fas fa-plus" aria-hidden="true"></i>
              {{ $type === \App\Enums\OrderType::Admission->value ? 'Admit patient' : 'Place order' }}
            </span>
            <span wire:loading wire:target="add">Working…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- The next step, under the work that opens it. --}}
  <x-ui.next-step :gate="$this->nextStep" />

  {{-- ── One order, opened to be worked on ───────────────────────
       A component of its own: the list is rendered on every visit dialog
       open, and file-upload state has no business riding along with it. --}}
  <livewire:visits.panels.order-detail :visit-id="$visitId" :key="'ord-detail-'.$visitId" />
</div>
