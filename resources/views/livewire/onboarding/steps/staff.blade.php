<div>
  @if($this->existing->isNotEmpty())
    <div class="wz-have" aria-label="Colleagues already added">
      @foreach($this->existing as $member)
        <span class="wz-have-item"><i class="fas fa-check" aria-hidden="true"></i>{{ $member->name }} · {{ $member->role_label }}</span>
      @endforeach
    </div>
  @endif

  <form wire:submit="invite" class="tb-mt-4">
    <div class="tb-form-grid">
      <x-ui.field label="Full name" for="wz-staffname" name="name" required>
        <input id="wz-staffname" type="text" wire:model="name" class="tb-input" required autocomplete="off">
      </x-ui.field>

      <x-ui.field label="Work email" for="wz-staffmail" name="email" required
                  hint="Their sign-in details are emailed here.">
        <input id="wz-staffmail" type="email" wire:model="email" class="tb-input" required autocomplete="off">
      </x-ui.field>

      <x-ui.field label="Role" for="wz-staffrole" name="role" required
                  hint="Decides what they can see and do.">
        <select id="wz-staffrole" wire:model="role" class="tb-select" required>
          <option value="">— choose a role —</option>
          @foreach($this->roles as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
      </x-ui.field>

      <x-ui.field label="Phone" for="wz-staffphone" name="phone">
        <input id="wz-staffphone" type="tel" wire:model="phone" class="tb-input">
      </x-ui.field>
    </div>

    <div class="wz-actions">
      <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="invite">
        <span wire:loading.remove wire:target="invite"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send the invitation</span>
        <span wire:loading wire:target="invite"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Inviting…</span>
      </button>
    </div>
  </form>
</div>
