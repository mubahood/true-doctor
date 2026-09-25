<div>
@if($demo)
  {{-- The button. Animated, but quietly: a slow breath rather than a flash,
       and it stops the moment a pointer is on it so it is not still moving
       while somebody is trying to read it. --}}
  <button type="button" class="tb-demo-cta" wire:click="open"
          aria-haspopup="dialog" title="You are in the demonstration hospital">
    <span class="tb-demo-dot" aria-hidden="true"></span>
    <span class="tb-demo-cta-text">Create your own hospital</span>
  </button>

  <x-ui.modal show="show" title="Ready to start your own?" size="md" :autosaves="true">
    <div class="tb-modal-body">

      {{-- What this is, before what to do about it. Somebody who has been
           entering patients for ten minutes needs to know where those went
           before they are asked to do anything else. --}}
      <div class="tb-demo-note">
        <i class="fas fa-flask" aria-hidden="true"></i>
        <div>
          <b>You are in the demonstration hospital.</b>
          <span>
            Everything here is sample data shared with everyone else trying the
            system, and nothing you enter is private.
            @if($resets)
              It is wiped and rebuilt every night at {{ $resetsAt }}.
            @else
              It is wiped and rebuilt periodically.
            @endif
          </span>
        </div>
      </div>

      <p class="tb-demo-lead">
        Your own hospital is separate from this one and from every other — its
        records are yours, visible to nobody else, and they stay.
      </p>

      <ul class="tb-demo-list">
        <li><i class="fas fa-check" aria-hidden="true"></i> <span><b>14 days free</b>, with every module switched on.</span></li>
        <li><i class="fas fa-check" aria-hidden="true"></i> <span><b>No card needed</b> to start, and nothing is charged if you stop.</span></li>
        <li><i class="fas fa-check" aria-hidden="true"></i> <span><b>Ready in about a minute</b> — a guided checklist sets up your departments, price list and staff.</span></li>
      </ul>

      <p class="tb-demo-fine">
        Signing out ends this demonstration session. Nothing you entered here
        comes with you — it belongs to the sample hospital, not to you.
      </p>
    </div>

    <div class="tb-modal-foot">
      {{-- "Not yet" is a real answer and gets a real button. --}}
      <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">
        Keep looking around
      </button>

      {{-- A POST, because it signs somebody out. One route, so there is no
           redirect target to smuggle anything into. --}}
      <form method="POST" action="{{ route('demo.leave') }}" class="tb-demo-go">
        @csrf
        <button type="submit" class="btn-tb btn-tb-primary">
          <i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i>
          Sign out and create my hospital
        </button>
      </form>
    </div>
  </x-ui.modal>
@endif
</div>
