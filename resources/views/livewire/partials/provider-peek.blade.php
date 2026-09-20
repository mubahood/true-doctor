{{-- One insurer, over the directory.

     The row gives the float and what members owe, side by side, and leaves the
     reader to subtract one from the other in their head — which is the figure
     that decides whether a settlement run can go ahead at all. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'Insurance provider'">
  @if($this->peeked)
    @php($p = $this->peeked)
    @php($fig = $this->peekedFigures)
    <div class="tb-modal-body">
      <div class="tb-peek-head">
        <div>
          <div class="tb-peek-when">{{ $p->name }}</div>
          <div class="tb-peek-time">{{ $p->code ?: 'No code' }}</div>
        </div>
        <div class="tb-peek-badges">
          <x-ui.badge :tone="$p->is_active ? 'active' : 'danger'">{{ $p->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
        </div>
      </div>

      <div class="tb-peek-figs">
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n">{{ \App\Support\HospitalSettings::money($p->float_balance) }}</span>
          <span class="tb-peek-fig-l">Float held</span>
        </div>
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n {{ bccomp($fig['owed'], '0', 2) > 0 ? 'is-bad' : '' }}">
            {{ \App\Support\HospitalSettings::money($fig['owed']) }}
          </span>
          <span class="tb-peek-fig-l">Members owe</span>
        </div>
        <div class="tb-peek-fig">
          <span class="tb-peek-fig-n {{ bccomp($fig['shortfall'], '0', 2) < 0 ? 'is-bad' : '' }}">
            {{ \App\Support\HospitalSettings::money($fig['shortfall']) }}
          </span>
          <span class="tb-peek-fig-l">{{ bccomp($fig['shortfall'], '0', 2) < 0 ? 'Short of clearing them' : 'Left after clearing them' }}</span>
        </div>
      </div>

      <dl class="tb-peek-facts">
        <dt>Cards</dt>
        <dd>
          {{ $fig['cards'] }} {{ \Illuminate\Support\Str::plural('card', $fig['cards']) }}
          @if($fig['inDebt'] > 0)
            <span class="tb-peek-bad">— {{ $fig['inDebt'] }} in debt</span>
          @else
            <span class="muted">— none in debt</span>
          @endif
        </dd>
        <dt>Credit limit</dt>
        <dd>
          {{ \App\Support\HospitalSettings::money($p->default_credit_limit) }}
          <span class="muted">by default on a new card</span>
        </dd>
        <dt>Contact</dt><dd>{{ $p->contact_person ?: '—' }}</dd>
        <dt>Phone</dt><dd>{{ $p->contact_phone ?: '—' }}</dd>
        <dt>Email</dt><dd>{{ $p->contact_email ?: '—' }}</dd>
      </dl>

      {{-- How the float got to the figure at the top. --}}
      <div class="tb-peek-sec">Last few float movements</div>
      @if($this->peekedLedger->isNotEmpty())
        <ul class="tb-peek-trail">
          @foreach($this->peekedLedger as $t)
            <li wire:key="peek-tx-{{ $t->id }}">
              <span class="tb-peek-step">
                <span @class(['tb-mv-qty', 'is-in' => bccomp($t->signedAmount(), '0', 2) >= 0])>
                  {{ bccomp($t->signedAmount(), '0', 2) < 0 ? '−' : '+' }}{{ \App\Support\HospitalSettings::money(ltrim($t->signedAmount(), '-')) }}
                </span>
                {{ $t->type->label() }} · float {{ \App\Support\HospitalSettings::money($t->balance_after) }}
              </span>
              <span class="tb-peek-meta">
                {{ $t->created_at?->format('j M · H:i') }}@if($t->createdBy) · {{ $t->createdBy->name }}@endif
              </span>
              @if($t->notes)<span class="tb-peek-note">{{ $t->notes }}</span>@endif
            </li>
          @endforeach
        </ul>
      @else
        <p class="tb-out-none">Nothing has been put on or taken off this float yet.</p>
      @endif
    </div>

    <div class="tb-modal-foot tb-peek-foot">
      @can('update', $p)
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit details
        </button>
      @endcan
      <span class="tb-peek-gap"></span>
      <button type="button" class="btn-tb btn-tb-ghost" wire:click="closePeek">Close</button>
      <a class="btn-tb btn-tb-primary" wire:navigate href="{{ route('admin.insurance-providers.show', $p) }}">
        <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record
      </a>
    </div>
  @endif
</x-ui.modal>
