<div class="wz">
  <h1 class="sr-only">Set up your hospital</h1>

  <div class="wz-main">
    {{-- ── Progress + what is still blocking ───────────────────────── --}}
    <div class="wz-head">
      <div>
        <h2>{{ $this->isComplete ? 'Your hospital is ready' : 'Finish setting up your hospital' }}</h2>
        <p>
          @if($this->isComplete)
            Every required step is done. You can revisit any of them at any time from Configuration.
          @else
            The rest of the system unlocks once these {{ $this->progress['total'] }} steps are done. Each one takes about a minute.
          @endif
        </p>
      </div>

      <x-ui.progress :value="$this->progress['done']" :max="$this->progress['total']"
                     label="Required setup" :tone="$this->isComplete ? 'ok' : 'primary'" />

      @if($this->blocking->isNotEmpty())
        <div class="wz-still tb-small" role="status">
          <span class="muted">Still needed:</span>
          @foreach($this->blocking as $step)
            <button type="button" class="wz-chip" wire:click="toggle('{{ $step->key }}')">{{ $step->title }}</button>
          @endforeach
        </div>
      @endif

      @if($this->isComplete)
        <div>
          <button type="button" wire:click="finish" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="finish">
            <i class="fas fa-arrow-right" aria-hidden="true"></i> Go to my dashboard
          </button>
        </div>
      @endif
    </div>

    {{-- ── Issues worth fixing even where a step passes ─────────────── --}}
    @if($this->issues !== [])
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Worth a second look</span></div>
        <div class="tb-card-body">
          <div class="wz-issues">
            @foreach($this->issues as $issue)
              <div class="wz-issue"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><span>{{ $issue }}</span></div>
            @endforeach
          </div>
        </div>
      </div>
    @endif

    {{-- ── The steps ────────────────────────────────────────────────── --}}
    @foreach($this->steps as $index => $step)
      @php($isOpen = $this->open === $step->key)
      <section @class(['wz-step', 'is-open' => $isOpen, 'is-done' => $step->done]) wire:key="wz-{{ $step->key }}"
               aria-labelledby="wz-title-{{ $step->key }}">
        <button type="button" class="wz-step-head" wire:click="toggle('{{ $step->key }}')"
                aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="wz-panel-{{ $step->key }}">
          <span class="wz-step-num" aria-hidden="true">
            @if($step->done)<i class="fas fa-check"></i>@else{{ $index + 1 }}@endif
          </span>
          <span class="wz-step-main">
            <span class="wz-step-title" id="wz-title-{{ $step->key }}">{{ $step->title }}</span>
            <span class="wz-step-sum">{{ $step->summary }}</span>
            <span class="wz-step-detail">{{ $step->detail }}</span>
          </span>
          <span class="wz-step-aside">
            <x-ui.badge :tone="$step->statusTone()">{{ $step->statusLabel() }}</x-ui.badge>
            <i class="fas fa-chevron-down wz-step-caret" aria-hidden="true"></i>
          </span>
        </button>

        @if($isOpen)
          <div class="wz-step-body" id="wz-panel-{{ $step->key }}">
            <div class="wz-why"><i class="fas fa-circle-info" aria-hidden="true"></i><span>{{ $step->why }}</span></div>

            @if($step->issues !== [])
              <div class="wz-issues">
                @foreach($step->issues as $issue)
                  <div class="wz-issue"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><span>{{ $issue }}</span></div>
                @endforeach
              </div>
            @endif

            @if($this->canConfigure)
              @switch($step->key)
                @case('profile')     <livewire:onboarding.steps.profile :key="'wz-form-profile'" /> @break
                @case('billing')     <livewire:onboarding.steps.billing :key="'wz-form-billing'" /> @break
                @case('departments') <livewire:onboarding.steps.departments :key="'wz-form-departments'" /> @break
                @case('services')    <livewire:onboarding.steps.services :key="'wz-form-services'" /> @break
                @case('staff')       <livewire:onboarding.steps.staff :key="'wz-form-staff'" /> @break
                @case('rooms')       <livewire:onboarding.steps.rooms :key="'wz-form-rooms'" /> @break
                @case('wards')       <livewire:onboarding.steps.wards :key="'wz-form-wards'" /> @break
                @case('catalogues')  <livewire:onboarding.steps.catalogues :key="'wz-form-catalogues'" /> @break
                @default
                  {{-- Defensive fallback only — every current step has a component above. --}}
                  <div class="wz-actions">
                    <x-ui.link :href="$step->route" class="btn-tb btn-tb-primary">
                      {{ $step->done ? 'Review' : 'Set up' }} {{ Str::lower($step->title) }} <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </x-ui.link>
                    <span class="muted tb-small">Optional — you can do this later.</span>
                  </div>
              @endswitch
            @else
              <p class="muted tb-small">Ask your hospital administrator to complete this step.</p>
            @endif

            @if($step->done && $index + 1 < $this->steps->count())
              <div class="wz-actions">
                <button type="button" wire:click="next" class="btn-tb btn-tb-primary">
                  Next <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </button>
              </div>
            @endif
          </div>
        @endif
      </section>
    @endforeach
  </div>

  {{-- ── Rail: the checklist at a glance + trial notice ────────────── --}}
  <aside class="wz-rail" aria-label="Setup checklist">
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Checklist</span></div>
      <div class="wz-mini">
        @foreach($this->steps as $step)
          <button type="button" wire:click="toggle('{{ $step->key }}')"
                  @class(['wz-mini-item', 'is-done' => $step->done, 'is-todo' => ! $step->done, 'is-current' => $this->open === $step->key])
                  @if($this->open === $step->key) aria-current="step" @endif>
            <span class="tick" aria-hidden="true"><i class="fas {{ $step->done ? 'fa-circle-check' : 'fa-circle' }}"></i></span>
            <span class="lbl">{{ $step->title }}</span>
            @unless($step->required)<span class="muted tb-xs">Optional</span>@endunless
            <span class="sr-only">{{ $step->statusLabel() }}</span>
          </button>
        @endforeach
      </div>
    </div>

    @if($this->subscription?->status->value === 'trialing')
      <div class="tb-card">
        <div class="tb-card-body">
          <div class="tb-flex tb-small"><i class="fas fa-clock" aria-hidden="true"></i> <b>Free trial</b></div>
          <p class="muted tb-small tb-mt-2">
            Your trial runs to {{ $this->subscription->trial_ends_at?->format('d M Y') ?? 'the end of the period' }}.
            Setting up now costs nothing.
          </p>
          <x-ui.link :href="route('admin.subscription.index')" class="btn-tb btn-tb-ghost btn-tb-sm tb-mt-3">View plans</x-ui.link>
        </div>
      </div>
    @endif

    <div class="tb-card">
      <div class="tb-card-body">
        <div class="tb-flex tb-small"><i class="fas fa-circle-question" aria-hidden="true"></i> <b>Stuck on a step?</b></div>
        <p class="muted tb-small tb-mt-2">Every step can also be done from its own page in the sidebar, and nothing here is final — you can change all of it later.</p>
      </div>
    </div>
  </aside>
</div>
