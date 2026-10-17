<div>
  <h1 class="sr-only">Cashier's day</h1>
  @php($money = fn ($v) => \App\Support\HospitalSettings::money($v))

  <div class="tb-filter-bar tb-toolbar">
    <button type="button" class="btn-tb btn-tb-sm" wire:click="shift(-1)" wire:loading.attr="disabled" wire:target="shift" aria-label="The day before">
      <i class="fas fa-chevron-left" aria-hidden="true"></i>
    </button>
    <input type="date" class="tb-input tb-input-slim" wire:model.live="date" max="{{ $today }}" aria-label="Day">
    <button type="button" class="btn-tb btn-tb-sm" wire:click="shift(1)" wire:loading.attr="disabled" wire:target="shift" @disabled($isToday) aria-label="The day after">
      <i class="fas fa-chevron-right" aria-hidden="true"></i>
    </button>
    @unless($isToday)
      <button type="button" class="btn-tb btn-tb-sm" wire:click="$set('date', '{{ $today }}')">Today</button>
    @endunless
    <a href="{{ route('admin.cashier.day.pdf', ['date' => $date]) }}" target="_blank" rel="noopener" class="btn-tb btn-tb-sm tb-ml-auto">
      <i class="fas fa-print" aria-hidden="true"></i> Print the day
    </a>
  </div>

  <div wire:loading.class="tb-loading" wire:target="date,shift">
    <div class="tb-stats-grid tb-mb-4" role="group" aria-label="The day's figures">
      <x-dash.stat icon="fa-sack-dollar" label="Received" :value="$money($s['total'])" tone="ok"
                   :sub="$s['payments_count'].' '.\Illuminate\Support\Str::plural('payment', $s['payments_count'])" />
      <x-dash.stat icon="fa-file-invoice-dollar" label="Invoices raised" :value="number_format($s['invoices_raised'])"
                   :sub="\Illuminate\Support\Carbon::parse($s['date'])->format('l d M Y')" />
      <x-dash.stat icon="fa-ban" label="Voided" :value="number_format($s['voided'])" :tone="$s['voided'] > 0 ? 'warn' : ''"
                   :sub="$s['voided'] > 0 ? 'Check each has a reason' : 'None'" />
    </div>

    <div class="tb-grid-2">
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-wallet" aria-hidden="true"></i> By method</span></div>
        <div class="tb-table-wrap"><table class="tb-table">
          <thead><tr><th>Method</th><th class="tb-text-right">Payments</th><th class="tb-text-right">Amount</th></tr></thead>
          <tbody>
            @forelse($s['totals_by_method'] as $row)
              <tr wire:key="m-{{ $row['method'] }}">
                <td>{{ \App\Enums\PaymentMethod::tryFrom($row['method'])?->label() ?? $row['method'] }}</td>
                <td class="tb-text-right">{{ $row['count'] }}</td>
                <td class="tb-text-right mono">{{ $money($row['amount']) }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="muted">No payments were taken {{ $isToday ? 'yet today' : 'this day' }}.</td></tr>
            @endforelse
          </tbody>
          @if($s['totals_by_method'] !== [])
            <tfoot><tr><th>Total</th><th class="tb-text-right">{{ $s['payments_count'] }}</th><th class="tb-text-right mono">{{ $money($s['total']) }}</th></tr></tfoot>
          @endif
        </table></div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-user-check" aria-hidden="true"></i> By person</span></div>
        <div class="tb-table-wrap"><table class="tb-table">
          <thead><tr><th>Received by</th><th class="tb-text-right">Total</th></tr></thead>
          <tbody>
            @forelse($s['by_user'] as $row)
              <tr wire:key="u-{{ $loop->index }}"><td>{{ $row['name'] }}</td><td class="tb-text-right mono">{{ $money($row['total']) }}</td></tr>
            @empty
              <tr><td colspan="2" class="muted">Nobody has taken a payment {{ $isToday ? 'yet today' : 'this day' }}.</td></tr>
            @endforelse
          </tbody>
        </table></div>
        <div class="tb-card-footer muted tb-xs">Count the drawer against the cash line. Mobile money and card are checked against their statements.</div>
      </div>
    </div>
  </div>
</div>
