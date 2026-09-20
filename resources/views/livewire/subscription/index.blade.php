<div>
  <x-ui.page-header title="Subscription" :crumbs="['Dashboard' => route('admin.dashboard'), 'Subscription' => null]" />

  @php($sub = $this->subscription)
  @php($days = $this->daysRemaining)

  {{-- ── Status ────────────────────────────────────────────────────────── --}}
  @if($sub)
    <div class="sub-status {{ $this->isBlocked ? 'is-blocked' : 'is-ok' }} tb-mb-4">
      <div class="sub-status-icon"><i class="fas {{ $this->isBlocked ? 'fa-triangle-exclamation' : 'fa-circle-check' }}" aria-hidden="true"></i></div>
      <div class="sub-status-main">
        <div class="tb-flex">
          <span class="sub-status-plan">{{ $sub->status->value === 'trialing' ? 'Free trial' : ($sub->plan?->name ?? '—') }}</span>
          <x-ui.badge :tone="match($sub->status->value) { 'active' => 'success', 'trialing' => 'warn', default => 'danger' }">{{ $sub->status->label() }}</x-ui.badge>
        </div>
        <p class="sub-status-line">
          @if($this->isBlocked)
            @if($sub->status->value === 'trialing')
              Your free trial ended {{ $sub->trial_ends_at?->diffForHumans() }}. Choose a plan below to get back in.
            @else
              Your subscription ended {{ $sub->ends_at?->diffForHumans() }}. Renew below to get back in.
            @endif
          @elseif($sub->status->value === 'trialing')
            Trial ends {{ $sub->trial_ends_at?->format('d M Y') }} — {{ $days }} {{ Str::plural('day', $days) }} left. Pick a plan any time before then.
          @elseif($sub->ends_at)
            Paid up to {{ $sub->ends_at->format('d M Y') }} — {{ $days }} {{ Str::plural('day', $days) }} left.
          @else
            Active, no end date.
          @endif
        </p>
      </div>
      @if($sub->plan && $sub->status->value !== 'trialing')
        <div class="sub-status-price">
          <span class="sub-price-main">{{ \App\Support\PlatformCurrency::format($sub->plan->price) }}<span class="muted">/mo</span></span>
          <span class="muted tb-xs">≈ {{ \App\Support\PlatformCurrency::formatUsd(\App\Support\PlatformCurrency::toUsd($sub->plan->price)) }}</span>
        </div>
      @endif
    </div>
  @else
    <div class="sub-empty tb-mb-4">
      <i class="fas fa-layer-group" aria-hidden="true"></i>
      <h2>Get your hospital set up with a plan</h2>
      <p class="muted">Choose a plan below to start. You can change plans at any time.</p>
    </div>
  @endif

  {{-- ── Plans — first, because this is what the page is for ───────────── --}}
  <h2 class="tb-subhead">{{ $this->currentPlanId ? 'Change plan' : 'Choose a plan' }}</h2>
  <div class="sub-plans">
    @foreach($this->plans as $plan)
      @php($isCurrent = $this->currentPlanId === $plan->id)
      <div class="sub-plan-card {{ $plan->is_featured ? 'is-featured' : '' }} {{ $isCurrent ? 'is-current' : '' }}" wire:key="plan-{{ $plan->id }}">
        @if($plan->is_featured)<span class="sub-plan-ribbon">Most popular</span>@endif
        <div class="sub-plan-name">{{ $plan->name }}</div>
        @if($plan->description)<p class="muted tb-small">{{ $plan->description }}</p>@endif

        <div class="sub-plan-price">
          {{ \App\Support\PlatformCurrency::format($plan->price) }}<small class="muted">/{{ $plan->billing_cycle->value === 'yearly' ? 'yr' : 'mo' }}</small>
        </div>
        <div class="muted tb-xs">≈ {{ \App\Support\PlatformCurrency::formatUsd(\App\Support\PlatformCurrency::toUsd($plan->price)) }}</div>

        <x-ui.pay-methods class="tb-mt-3" />

        <ul class="sub-plan-features">
          @forelse((array) $plan->features as $feature)
            <li><i class="fas fa-check" aria-hidden="true"></i>{{ $feature }}</li>
          @empty
            <li><i class="fas fa-check" aria-hidden="true"></i>{{ $plan->limit('max_staff') ? $plan->limit('max_staff').' staff accounts' : 'Unlimited staff' }}</li>
            <li><i class="fas fa-check" aria-hidden="true"></i>{{ $plan->limit('max_patients') ? number_format($plan->limit('max_patients')).' patients' : 'Unlimited patients' }}</li>
            <li><i class="fas fa-check" aria-hidden="true"></i>{{ $plan->limit('max_beds') ? $plan->limit('max_beds').' beds' : 'Unlimited beds' }}</li>
          @endforelse
        </ul>

        @if($isCurrent)
          <button type="button" class="btn-tb btn-tb-ghost tb-w-full" wire:click="openWizard({{ $plan->id }})">Extend this plan</button>
        @else
          <button type="button" class="btn-tb btn-tb-primary tb-w-full" wire:click="openWizard({{ $plan->id }})">
            {{ $sub && $sub->plan_id === $plan->id && $sub->status->value !== 'trialing' ? 'Renew' : 'Subscribe' }}
          </button>
        @endif
      </div>
    @endforeach
  </div>
  <p class="muted tb-small tb-mt-3"><i class="fas fa-lock" aria-hidden="true"></i> Secure payment via Pesapal. Billed in Ugandan shillings; pay by mobile money or card.</p>

  {{-- ── Payment history ──────────────────────────────────────────────── --}}
  @if($sub)
    <h2 class="tb-subhead">Payment history</h2>
    <div class="tb-card">
      <div class="tb-table-wrap">
        <table class="tb-table">
          <thead><tr><th>Date</th><th>Method</th><th>Period</th><th>Reference</th><th class="tb-text-right">Amount</th></tr></thead>
          <tbody>
            @forelse($this->payments as $payment)
              <tr wire:key="pay-{{ $payment->id }}">
                <td>{{ $payment->paid_at?->format('d M Y, H:i') }}</td>
                <td class="muted sub-method">{{ $payment->method }}</td>
                <td class="muted">{{ $payment->notes ?? '—' }}</td>
                <td class="mono muted tb-xs">{{ $payment->reference ?? '—' }}</td>
                <td class="tb-text-right">
                  {{ \App\Support\PlatformCurrency::format($payment->amount) }}
                  <div class="muted tb-xs">≈ {{ \App\Support\PlatformCurrency::formatUsd(\App\Support\PlatformCurrency::toUsd($payment->amount)) }}</div>
                </td>
              </tr>
            @empty
              <tr><td colspan="5"><x-ui.empty icon="fa-receipt" noun="payments" /></td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  @endif

  {{-- ── Subscribe wizard ─────────────────────────────────────────────── --}}
  <x-ui.modal show="showWizard" title="{{ $this->wizardPlan?->name ?? 'Subscribe' }}" size="sm">
    @if($this->wizardPlan)
      @php($plan = $this->wizardPlan)
      <div class="tb-modal-body">
        <x-ui.progress :value="$wizardStep" :max="2" label="Step {{ $wizardStep }} of 2" :show-value="false" />

        @if($wizardStep === 1)
          <div class="sub-wiz-review">
            <span class="tb-label">How long do you want to pay for?</span>
            <div class="sub-months" role="group" aria-label="Number of months">
              @foreach($this->monthOptions as $option)
                <button type="button" wire:click="$set('months', {{ $option }})"
                        @class(['sub-month', 'is-on' => $months === $option])
                        aria-pressed="{{ $months === $option ? 'true' : 'false' }}">
                  {{ $option }} {{ Str::plural('month', $option) }}
                </button>
              @endforeach
            </div>

            <div class="sub-total" aria-live="polite">
              <div class="tb-flex tb-flex-between">
                <span class="muted tb-small">{{ \App\Support\PlatformCurrency::format($plan->price) }} × {{ $months }} {{ Str::plural('month', $months) }}</span>
                <span class="sub-total-amount">{{ \App\Support\PlatformCurrency::format($this->wizardTotal) }}</span>
              </div>
              <div class="muted tb-xs tb-mt-2">
                ≈ {{ \App\Support\PlatformCurrency::formatUsd(\App\Support\PlatformCurrency::toUsd($this->wizardTotal)) }} ·
                covers you to <b>{{ $this->wizardCoversUntil }}</b>
              </div>
            </div>

            <ul class="sub-plan-features">
              @forelse((array) $plan->features as $feature)
                <li><i class="fas fa-check" aria-hidden="true"></i>{{ $feature }}</li>
              @empty
                <li><i class="fas fa-check" aria-hidden="true"></i>{{ $plan->limit('max_staff') ? $plan->limit('max_staff').' staff accounts' : 'Unlimited staff' }}</li>
                <li><i class="fas fa-check" aria-hidden="true"></i>{{ $plan->limit('max_patients') ? number_format($plan->limit('max_patients')).' patients' : 'Unlimited patients' }}</li>
              @endforelse
            </ul>
          </div>
        @else
          <div class="sub-wiz-billing">
            <div class="sub-total tb-mb-4">
              <div class="tb-flex tb-flex-between">
                <span class="muted tb-small">{{ $plan->name }} · {{ $months }} {{ Str::plural('month', $months) }}</span>
                <span class="sub-total-amount">{{ \App\Support\PlatformCurrency::format($this->wizardTotal) }}</span>
              </div>
            </div>

            <x-ui.field label="Email" for="wiz-email" name="_email">
              <input id="wiz-email" type="email" class="tb-input" value="{{ auth()->user()->email }}" disabled>
            </x-ui.field>
            <x-ui.field label="Phone (for mobile money)" for="wiz-phone" name="phone" hint="Pesapal needs a phone or email to reach you.">
              <input id="wiz-phone" type="tel" wire:model.live.debounce.400ms="phone" class="tb-input" placeholder="+256 700 000 000">
            </x-ui.field>

            <x-ui.pay-methods class="tb-mt-3" />
          </div>
        @endif
      </div>
      <div class="tb-modal-foot">
        @if($wizardStep === 1)
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="button" class="btn-tb btn-tb-primary" wire:click="wizardNext">Continue <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
        @else
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="wizardBack">Back</button>
          {{-- Classic POST: this step alone ends in the external Pesapal redirect. --}}
          <form method="post" action="{{ route('admin.subscription.checkout', $plan) }}" style="display:contents;"
                x-data
                x-on:submit="$el.querySelector('button[type=submit]').setAttribute('disabled', 'disabled')">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone }}">
            <input type="hidden" name="months" value="{{ $months }}">
            {{-- Disabled on submit rather than by wire:loading: this leaves the
                 SPA for the hosted checkout, so there is no round-trip to hook,
                 and a second click is a second subscription payment. --}}
            <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled">
              <i class="fas fa-lock" aria-hidden="true"></i> Pay {{ \App\Support\PlatformCurrency::format($this->wizardTotal) }}
            </button>
          </form>
        @endif
      </div>
    @endif
  </x-ui.modal>
</div>
