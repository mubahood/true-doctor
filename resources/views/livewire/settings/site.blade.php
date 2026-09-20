<div>
  <x-ui.page-header title="Site settings" :crumbs="['Dashboard' => route('admin.dashboard'), 'Site settings' => null]" />

  <form wire:submit="save">
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Site configuration</span></div>
      <div class="tb-card-body">
        <div class="tb-form-grid">
          <x-ui.field label="Site name" for="set-name" name="site_name" required>
            <input id="set-name" type="text" wire:model="site_name" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Tagline" for="set-tagline" name="tagline" required>
            <input id="set-tagline" type="text" wire:model="tagline" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Contact email" for="set-email" name="contact_email">
            <input id="set-email" type="email" wire:model="contact_email" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Contact phone" for="set-phone" name="contact_phone">
            <input id="set-phone" type="text" wire:model="contact_phone" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Verify rate limit (per minute)" for="set-rate" name="verify_rate_limit" required>
            <input id="set-rate" type="number" wire:model="verify_rate_limit" class="tb-input">
          </x-ui.field>
        </div>
      </div>
    </div>

    <div class="tb-flex tb-mt-3">
      <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
        <span wire:loading.remove wire:target="save"><i class="fas fa-save" aria-hidden="true"></i> Save settings</span>
        <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
      </button>
    </div>
  </form>
</div>
