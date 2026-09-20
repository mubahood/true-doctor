{{-- The capture screens.

     One tab per thing a clinician is actually doing away from a desk: a ward
     round, the bench, a consultation note, looking somebody up, registering
     somebody new — plus the two that are about the queue itself.

     Anything NOT here needs a connection and says so rather than being hidden:
     somebody who cannot find the billing screen should be told why, not left
     to wonder whether they are looking in the wrong place. --}}
<div x-data="{ tab: 'ward' }">
  <div class="tb-seg tb-mb-4" role="tablist" aria-label="What you are doing">
    <button type="button" role="tab" class="tb-seg-btn" :aria-selected="tab === 'ward'" :class="tab === 'ward' && 'is-on'"
            @click="tab = 'ward'"><i class="fas fa-bed-pulse" aria-hidden="true"></i> Ward round</button>
    <button type="button" role="tab" class="tb-seg-btn" :aria-selected="tab === 'bench'" :class="tab === 'bench' && 'is-on'"
            @click="tab = 'bench'"><i class="fas fa-flask" aria-hidden="true"></i> Bench</button>
    <button type="button" role="tab" class="tb-seg-btn" :aria-selected="tab === 'notes'" :class="tab === 'notes' && 'is-on'"
            @click="tab = 'notes'"><i class="fas fa-pen-nib" aria-hidden="true"></i> Notes</button>
    <button type="button" role="tab" class="tb-seg-btn" :aria-selected="tab === 'find'" :class="tab === 'find' && 'is-on'"
            @click="tab = 'find'"><i class="fas fa-magnifying-glass" aria-hidden="true"></i> Find a patient</button>
    <button type="button" role="tab" class="tb-seg-btn" :aria-selected="tab === 'new'" :class="tab === 'new' && 'is-on'"
            @click="tab = 'new'"><i class="fas fa-user-plus" aria-hidden="true"></i> Register</button>
    <button type="button" role="tab" class="tb-seg-btn" :aria-selected="tab === 'unsent'" :class="tab === 'unsent' && 'is-on'"
            @click="tab = 'unsent'"><i class="fas fa-clock" aria-hidden="true"></i> Not yet sent</button>
    <button type="button" role="tab" class="tb-seg-btn" :aria-selected="tab === 'conflicts'" :class="tab === 'conflicts' && 'is-on'"
            @click="tab = 'conflicts'">
      <i class="fas fa-code-branch" aria-hidden="true"></i> Decisions
      <span x-show="$store.offline.status?.conflicts > 0"
            x-text="'(' + $store.offline.status.conflicts + ')'"></span>
    </button>
  </div>

  <div x-show="tab === 'ward'">@include('field.partials.ward')</div>
  <div x-show="tab === 'bench'" x-cloak>@include('field.partials.bench')</div>
  <div x-show="tab === 'notes'" x-cloak>@include('field.partials.notes')</div>
  <div x-show="tab === 'find'" x-cloak>@include('field.partials.find')</div>
  <div x-show="tab === 'new'" x-cloak>@include('field.partials.register')</div>
  <div x-show="tab === 'unsent'" x-cloak>@include('field.partials.unsent')</div>
  <div x-show="tab === 'conflicts'" x-cloak>@include('field.partials.conflicts')</div>

  <div class="tb-card tb-mt-4">
    <div class="tb-card-header">
      <span class="tb-card-title">
        <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> Billing, payments and cards
      </span>
      <span class="badge-tb badge-neutral">Needs a connection</span>
    </div>
    <div class="tb-card-body muted tb-small">
      <strong>These need a connection.</strong> Balances and receipt numbers are worked out on the
      server under a lock; a figure calculated on this device could be wrong, and a receipt showing
      a wrong balance is worse than no receipt. Use the main panel when you are back online.
    </div>
  </div>
</div>
