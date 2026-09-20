<div>
  <x-ui.modal size="md" show="showProfile" title="My profile">
    <form wire:submit="saveProfile" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Full name" for="pf-name" required>
          <input id="pf-name" type="text" wire:model="name" class="tb-input" required autocomplete="name">
        </x-ui.field>
        <x-ui.field label="Phone" for="pf-phone">
          <input id="pf-phone" type="text" wire:model="phone" class="tb-input" autocomplete="tel">
        </x-ui.field>
        <x-ui.field label="Bio" for="pf-bio">
          <textarea id="pf-bio" wire:model="bio" class="tb-textarea" rows="3"></textarea>
        </x-ui.field>
        <x-ui.field label="Photo" for="pf-avatar" hint="JPG, PNG or WebP up to 5 MB.">
          <input id="pf-avatar" type="file" wire:model.live="avatar" accept="image/*" class="tb-input">
          <div wire:loading wire:target="avatar" class="muted tb-small tb-mt-2"><i class="fas fa-circle-notch fa-spin"></i> Uploading…</div>
          @if($avatar)
            <img src="{{ $avatar->temporaryUrl() }}" alt="Preview" class="tb-mt-2" style="width:64px;height:64px;object-fit:cover;border:1px solid var(--line);">
          @elseif(auth()->user()->avatar_url)
            <label class="tb-check-group tb-mt-2"><input type="checkbox" wire:model="remove_avatar"> Remove current photo</label>
          @endif
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveProfile,avatar">
          <span wire:loading.remove wire:target="saveProfile"><i class="fas fa-check"></i> Save</span>
          <span wire:loading wire:target="saveProfile"><i class="fas fa-spinner fa-spin"></i> Saving…</span>
        </button>
      </div>
    </form>
  </x-ui.modal>

  <x-ui.modal size="md" show="showPassword" title="Change password">
    <form wire:submit="savePassword" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Current password" for="pw-current" required>
          <input id="pw-current" type="password" wire:model="current_password" class="tb-input" autocomplete="current-password" required>
        </x-ui.field>
        <x-ui.field label="New password" for="pw-new" required hint="At least 8 characters with letters and numbers.">
          <input id="pw-new" type="password" wire:model="password" class="tb-input" autocomplete="new-password" required>
        </x-ui.field>
        <x-ui.field label="Confirm new password" for="pw-confirm" required>
          <input id="pw-confirm" type="password" wire:model="password_confirmation" class="tb-input" autocomplete="new-password" required>
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="savePassword">
          <span wire:loading.remove wire:target="savePassword"><i class="fas fa-lock"></i> Update password</span>
          <span wire:loading wire:target="savePassword"><i class="fas fa-spinner fa-spin"></i> Updating…</span>
        </button>
      </div>
    </form>
  </x-ui.modal>
</div>
