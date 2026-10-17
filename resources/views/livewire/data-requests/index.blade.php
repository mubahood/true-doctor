<div>
  <x-ui.page-header title="Data requests" :sr="true">
    <x-slot:explain>
      Every time a patient (or their parent or guardian) asks to see their data, correct it, or stop a use of it.
      The Data Protection and Privacy Act expects an answer without delay; each request is due {{ config('compliance.response_days') }} days after it was received.
      How to answer each kind: Settings, Data protection, Patients' rights procedure.
    </x-slot:explain>
  </x-ui.page-header>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search name or patient number…" aria-label="Search data requests">
    </div>
    <select wire:model.live="status" class="tb-select" aria-label="Status">
      <option value="">Every status</option>
      @foreach($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
    </select>
    <select wire:model.live="kind" class="tb-select" aria-label="Kind of request">
      <option value="">Every kind</option>
      @foreach($kinds as $k)<option value="{{ $k->value }}">{{ $k->label() }}</option>@endforeach
    </select>
    <div class="tb-toolbar-actions">
      <x-ui.link :href="route('admin.settings.data-protection')" class="btn-tb btn-tb-sm"><i class="fas fa-file-shield" aria-hidden="true"></i> Documents</x-ui.link>
      <button type="button" wire:click="create" class="btn-tb btn-tb-primary btn-tb-sm"><i class="fas fa-plus" aria-hidden="true"></i> Log a request</button>
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,status,kind,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Who asked</th><th>Patient</th><th>Asked to</th><th>Received</th><th>Due</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="data-request-{{ $r->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          <td class="tb-fw-500"><button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})" title="See this request">{{ $r->requester_name }}</button></td>
          <td class="tb-small">{{ $r->patient?->full_name ?? '-' }}@if($r->patient)<div class="muted tb-xs mono">{{ $r->patient->patient_no }}</div>@endif</td>
          <td class="tb-small">{{ $r->kind->label() }}</td>
          <td class="tb-small tb-nowrap">{{ $r->received_on->format('d M Y') }}</td>
          <td class="tb-small tb-nowrap">
            {{ $r->due_on->format('d M Y') }}
            @if($r->isOverdue())<div><x-ui.badge tone="danger">Overdue</x-ui.badge></div>@endif
          </td>
          <td><x-ui.badge :tone="$r->status->tone()">{{ $r->status->label() }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap"><x-ui.icon-button label="See the request from {{ $r->requester_name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" /></td>
        </tr>
      @empty
        <tr><td colspan="8"><x-ui.empty icon="fa-user-shield" noun="data requests" :filtered="$filtered" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Log a request ─────────────────────────────────────────── --}}
  <x-ui.modal show="showForm" size="md" title="Log a data request">
    @if($showForm)
    <form wire:submit="save" class="dp-form">
      <div class="tb-modal-body">
        <x-ui.field label="Patient" name="patient_id" hint="Leave empty if they are not on your register.">
          <livewire:ui.select-search resource="patients" name="patient_id" :selected="$patient_id" placeholder="Search patients…" :key="'dr-patient-'.$formNonce" />
        </x-ui.field>
        <div class="tb-form-grid">
          <x-ui.field label="Who asked" for="dr-name" name="requester_name" required hint="The patient, or the parent or guardian who asked for them.">
            <input id="dr-name" type="text" wire:model="requester_name" class="tb-input" maxlength="150" required>
          </x-ui.field>
          <x-ui.field label="Their phone or email" for="dr-contact" name="requester_contact">
            <input id="dr-contact" type="text" wire:model="requester_contact" class="tb-input" maxlength="150">
          </x-ui.field>
          <x-ui.field label="They asked to" for="dr-kind" name="request_kind" required>
            <select id="dr-kind" wire:model="request_kind" class="tb-select">
              @foreach($kinds as $k)<option value="{{ $k->value }}">{{ $k->label() }}</option>@endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="Received on" for="dr-received" name="received_on" required>
            <input id="dr-received" type="date" wire:model="received_on" class="tb-input" max="{{ today()->toDateString() }}" required>
          </x-ui.field>
        </div>
        <x-ui.field label="What they asked" for="dr-details" name="details">
          <textarea id="dr-details" wire:model="details" class="tb-textarea" rows="3" maxlength="2000"></textarea>
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Log request</button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  {{-- ── One request, over the list ────────────────────────────── --}}
  <x-ui.modal show="showPeek" size="md" :title="$this->peeked ? 'Request from '.$this->peeked->requester_name : 'Data request'">
    @if($this->peeked)
      @php($req = $this->peeked)
      <div class="tb-modal-body">
        <x-ui.peek-head :heading="$req->kind->label()" :sub="'Received '.$req->received_on->format('j M Y').', due '.$req->due_on->format('j M Y')">
          <x-ui.badge :tone="$req->status->tone()">{{ $req->status->label() }}</x-ui.badge>
          @if($req->isOverdue())<x-ui.badge tone="danger">Overdue</x-ui.badge>@endif
        </x-ui.peek-head>

        <x-ui.peek-figs :figures="[
          ['label' => 'Who asked', 'value' => $req->requester_name, 'sub' => $req->requester_contact],
          ['label' => 'Patient', 'value' => $req->patient?->full_name ?? 'Not on the register', 'sub' => $req->patient?->patient_no],
          ['label' => 'Logged by', 'value' => $req->recorder?->name ?? '-', 'sub' => $req->created_at?->format('j M Y H:i')],
        ]" />

        @if($req->details)
          <div class="tb-peek-sec">What they asked</div>
          <p class="tb-peek-note">{{ $req->details }}</p>
        @endif

        <div class="tb-peek-sec">How to answer</div>
        <p class="tb-peek-note">{{ $req->kind->hint() }}</p>
        @if($req->patient)
          <div class="dp-actions">
            @if($req->kind === \App\Enums\DataRequestKind::Access)
              {{-- target first: the guard reads a tag up to its first ">", and "->" has one. --}}
              <a target="_blank" rel="noopener" href="{{ route('admin.patients.personal-data', ['patient' => $req->patient, 'format' => 'pdf']) }}" class="btn-tb btn-tb-sm"><i class="fas fa-file-pdf" aria-hidden="true"></i> Their data (PDF)</a>
              <a target="_blank" rel="noopener" href="{{ route('admin.patients.personal-data', ['patient' => $req->patient, 'format' => 'json']) }}" class="btn-tb btn-tb-sm"><i class="fas fa-file-code" aria-hidden="true"></i> As a data file</a>
            @endif
            <x-ui.link :href="route('admin.patients.show', $req->patient)" class="btn-tb btn-tb-sm"><i class="fas fa-user" aria-hidden="true"></i> Open their record</x-ui.link>
          </div>
        @endif

        @if($req->status === \App\Enums\DataRequestStatus::Open)
          <x-ui.field label="What was done" for="dr-outcome" name="outcome" hint="Kept in the log as the answer. For a refusal, the reason the patient is given.">
            <textarea id="dr-outcome" wire:model="outcome" class="tb-textarea" rows="3" maxlength="2000"></textarea>
          </x-ui.field>
        @else
          <div class="tb-peek-sec">{{ $req->status === \App\Enums\DataRequestStatus::Done ? 'Answered' : 'Refused' }} {{ $req->closed_at?->format('j M Y') }} by {{ $req->closer?->name ?? '-' }}</div>
          <p class="tb-peek-note">{{ $req->outcome }}</p>
        @endif
      </div>
      @if($req->status === \App\Enums\DataRequestStatus::Open)
        <x-ui.peek-foot>
          <button type="button" class="btn-tb btn-tb-sm" wire:click="refuse" wire:loading.attr="disabled" wire:target="refuse,answer">Refuse, with the reason</button>
          <button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="answer" wire:loading.attr="disabled" wire:target="refuse,answer"><i class="fas fa-check" aria-hidden="true"></i> Mark as answered</button>
        </x-ui.peek-foot>
      @endif
    @endif
  </x-ui.modal>
</div>
