<div>
  @php($destructive = [\App\Enums\ClaimStatus::Rejected, \App\Enums\ClaimStatus::Cancelled])

  <x-ui.page-header :title="$claim->claim_no"
    :crumbs="['Claims' => route('admin.insurance-claims.index'), $claim->patient?->full_name ?? 'Claim' => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <x-ui.badge :tone="$claim->status->badge()">{{ $claim->status->label() }}</x-ui.badge>
        @if($claim->patient)
          <x-ui.link :href="route('admin.patients.show', $claim->patient)" class="tb-small">{{ $claim->patient->full_name }}</x-ui.link>
        @endif
        @if($claim->provider)<span class="muted tb-small">{{ $claim->provider->name }}</span>@endif
        <x-ui.money :amount="$claim->amount" class="tb-fw-600" />
      </div>
    </x-slot:subtitle>
  </x-ui.page-header>

  <div class="tb-detail-cols">
    {{-- ── Claim summary ────────────────────────────────────────── --}}
    <div class="tb-card">
      <div class="tb-card-body tb-text-center">
        <div class="mono tb-primary">{{ $claim->claim_no }}</div>
        <div class="tb-mt-2"><x-ui.badge :tone="$claim->status->badge()">{{ $claim->status->label() }}</x-ui.badge></div>
        <div class="tb-mt-2"><x-ui.money :amount="$claim->amount" class="tb-fw-600" /></div>
      </div>
      <div class="tb-table-wrap"><table class="tb-table">
        <caption class="sr-only">Claim details</caption>
        <tbody>
          <tr><th scope="row">Patient</th><td>
            @if($claim->patient)
              <x-ui.link :href="route('admin.patients.show', $claim->patient)">{{ $claim->patient->full_name }}</x-ui.link>
              <div class="muted tb-xs mono">{{ $claim->patient->patient_no }}</div>
            @else — @endif
          </td></tr>
          <tr><th scope="row">Provider</th><td>{{ $claim->provider?->name ?? '—' }}</td></tr>
          <tr><th scope="row">Invoice</th><td>
            @if($claim->invoice)
              <x-ui.link :href="route('admin.invoices.show', $claim->invoice)">{{ $claim->invoice->invoice_no }}</x-ui.link>
            @else — @endif
          </td></tr>
          <tr><th scope="row">Amount</th><td><x-ui.money :amount="$claim->amount" /></td></tr>
          <tr><th scope="row">Notes</th><td>{{ $claim->notes ?? '—' }}</td></tr>
        </tbody>
      </table></div>
    </div>

    <div class="tb-stack">
      {{-- ── Transitions ────────────────────────────────────────── --}}
      @can('manage', \App\Models\InsuranceClaim::class)
        @if($claim->status->transitionsTo())
          <div class="tb-card">
            <div class="tb-card-header"><span class="tb-card-title">Advance</span></div>
            <div class="tb-card-body">
              <div class="tb-flex">
                @foreach($claim->status->transitionsTo() as $next)
                  @php($isDestructive = in_array($next, $destructive, true))
                  @php($isMoney = $next === \App\Enums\ClaimStatus::Paid)
                  <button type="button" wire:key="claim-transition-{{ $next->value }}"
                    wire:click="transition('{{ $next->value }}')"
                    wire:loading.attr="disabled" wire:target="transition('{{ $next->value }}')"
                    @if($isMoney) wire:confirm="Mark this claim paid? A payment for the claim amount is recorded on the linked invoice." @endif
                    @if($isDestructive) wire:confirm="Move this claim to {{ $next->label() }}? This cannot be undone." @endif
                    class="btn-tb btn-tb-sm {{ $isDestructive ? 'btn-tb-danger' : 'btn-tb-primary' }}">
                    <span wire:loading.remove wire:target="transition('{{ $next->value }}')">{{ $next->label() }}</span>
                    <span wire:loading wire:target="transition('{{ $next->value }}')"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
                  </button>
                @endforeach
              </div>

              @if(array_filter($claim->status->transitionsTo(), fn ($s) => in_array($s, $destructive, true)))
                <x-ui.field label="Reason" for="claim-note" name="note" class="tb-mt-3"
                  hint="Recorded on the claim when you reject or cancel it.">
                  <input id="claim-note" type="text" class="tb-input" wire:model="note" maxlength="255">
                </x-ui.field>
              @endif
            </div>
          </div>
        @endif
      @endcan

      {{-- ── History ────────────────────────────────────────────── --}}
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">History</span></div>
        <div class="tb-card-body">
          <div class="tb-timeline">
            <div class="tb-tl-item">
              <div class="t">Raised</div>
              <div class="m">{{ $claim->created_at?->format('d M Y H:i') ?? '—' }}</div>
            </div>
            @if($claim->submitted_at)
              <div class="tb-tl-item">
                <div class="t">Submitted to {{ $claim->provider?->name ?? 'the provider' }}</div>
                <div class="m">{{ $claim->submitted_at->format('d M Y H:i') }}</div>
              </div>
            @endif
            @if($claim->resolved_at)
              <div class="tb-tl-item">
                <div class="t">{{ $claim->status->label() }}</div>
                <div class="m">{{ $claim->resolved_at->format('d M Y H:i') }}</div>
                @if($claim->notes)<div class="m">{{ $claim->notes }}</div>@endif
              </div>
            @endif
            @if($claim->payment_id && $claim->invoice)
              <div class="tb-tl-item">
                <div class="t">Payment recorded on {{ $claim->invoice->invoice_no }}</div>
                <div class="m"><x-ui.money :amount="$claim->amount" /> settled through the billing ledger.</div>
              </div>
            @endif
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
