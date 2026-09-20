<form wire:submit="save">
  <div class="tb-form-grid">
    <x-ui.field label="Hospital name" for="wz-name" name="name" required full>
      <input id="wz-name" type="text" wire:model="name" class="tb-input" required autocomplete="organization">
    </x-ui.field>

    <x-ui.field label="Address" for="wz-address" name="address" required full
                hint="Printed on invoices, receipts and reports.">
      <input id="wz-address" type="text" wire:model="address" class="tb-input" required
             placeholder="Plot 12, Kampala Road, Kampala">
    </x-ui.field>

    <x-ui.field label="Phone" for="wz-phone" name="phone">
      <input id="wz-phone" type="tel" wire:model="phone" class="tb-input" placeholder="+256 700 000 000" autocomplete="tel">
    </x-ui.field>

    <x-ui.field label="Email" for="wz-email" name="email">
      <input id="wz-email" type="email" wire:model="email" class="tb-input" placeholder="info@hospital.org">
    </x-ui.field>

    <x-ui.field label="Time zone" for="wz-tz" name="timezone" required
                hint="Appointment times and reports use this.">
      <select id="wz-tz" wire:model="timezone" class="tb-select" required>
        @foreach($timezones as $tz)<option value="{{ $tz }}">{{ str_replace('_', ' ', $tz) }}</option>@endforeach
      </select>
    </x-ui.field>

    {{-- Asked for here rather than left on a settings page a new hospital has
         no reason to visit: without it the first invoice they ever send goes
         out plain, and nothing had asked. Optional — a hospital without a logo
         is still a hospital. --}}
    <div class="tb-form-group full">
      <x-ui.drop-file name="logo" :has="$logo || $currentLogo"
                      accept="image/png,image/jpeg,image/gif"
                      label="Logo (optional)"
                      idle="Drop your logo here, or click to choose one"
                      hint="Printed at the top of every document. PNG with a transparent background looks best.">
        <x-slot:preview>
          @if($this->logoPreviewUrl())
            <img src="{{ $this->logoPreviewUrl() }}" alt="The logo you just chose">
          @elseif($currentLogo)
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($currentLogo) }}" alt="Your current logo">
          @endif
        </x-slot:preview>
      </x-ui.drop-file>
    </div>
  </div>

  <div class="wz-actions">
    <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
      <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save</span>
      <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
    </button>
  </div>
</form>
