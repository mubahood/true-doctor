<div>
  <x-ui.page-header title="Hospital letterhead"
                    :crumbs="['Dashboard' => route('admin.dashboard'), 'Hospital letterhead' => null]">
    <x-slot:explain>
      What appears at the top of everything the system prints — invoices, receipts,
      lab and radiology reports, discharge summaries and patient ID cards. Set it once.
    </x-slot:explain>
  </x-ui.page-header>

  <form wire:submit="save">
    {{-- Two columns, because the answer and the consequence belong beside each
         other: the preview on the right is the real letterhead, redrawn as the
         fields on the left are typed. Stacked, the preview sat two screens
         below the thing it was previewing. --}}
    <div class="tb-letterhead">
      <div class="tb-lh-form">
        <x-ui.drop-file name="logo" :has="$logo || $currentLogo" accept="image/png,image/jpeg,image/gif">
          <x-slot:preview>
            @if($this->logoPreviewUrl())
              <img src="{{ $this->logoPreviewUrl() }}" alt="The logo you just chose">
            @elseif($currentLogo)
              <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($currentLogo) }}" alt="Your current logo">
            @endif
          </x-slot:preview>

          <x-slot:actions>
            @if($currentLogo && ! $logo)
              <button type="button" class="tb-lh-remove"
                      wire:click="removeLogo" wire:confirm="Remove the logo from every document?">
                <i class="fas fa-trash" aria-hidden="true"></i> Remove
              </button>
            @endif
          </x-slot:actions>
        </x-ui.drop-file>

        <div class="tb-form-grid tb-mt-3">
          <x-ui.field label="Hospital name" for="hosp-name" name="name" required class="full">
            <input id="hosp-name" type="text" wire:model.live.debounce.300ms="name" class="tb-input" required maxlength="150">
          </x-ui.field>

          <x-ui.field label="Phone" for="hosp-phone" name="phone">
            <input id="hosp-phone" type="text" wire:model.live.debounce.300ms="phone" class="tb-input" maxlength="64">
          </x-ui.field>

          <x-ui.field label="Email" for="hosp-email" name="email">
            <input id="hosp-email" type="email" wire:model.live.debounce.300ms="email" class="tb-input" maxlength="191">
          </x-ui.field>

          <x-ui.field label="Website" for="hosp-website" name="website">
            <input id="hosp-website" type="text" wire:model.live.debounce.300ms="website" class="tb-input" maxlength="191">
          </x-ui.field>

          <x-ui.field label="Licence or tax number" for="hosp-reg" name="registration">
            <input id="hosp-reg" type="text" wire:model.live.debounce.300ms="registration" class="tb-input" maxlength="120">
          </x-ui.field>

          <x-ui.field label="Address" for="hosp-address" name="address" class="full">
            <textarea id="hosp-address" wire:model.live.debounce.300ms="address" class="tb-input" rows="2" maxlength="500"></textarea>
          </x-ui.field>

          <x-ui.field label="Footer line" for="hosp-footer" name="footer" class="full"
                      hint="The last line of every document. Same setting as the invoice footer under Billing.">
            <input id="hosp-footer" type="text" wire:model.live.debounce.300ms="footer" class="tb-input" maxlength="255">
            <x-ui.suggestions set="footer" :current="$footer"
                              :options="['Thank you for choosing us.', 'Payment is due within 30 days.', 'This is a computer-generated document and needs no signature.']" />
          </x-ui.field>
        </div>
      </div>

      {{-- A letterhead nobody can see before they send an invoice is a
           letterhead nobody checks. This is the real one, at the real
           proportions, and it sticks so it stays in view while you type. --}}
      <aside class="tb-lh-preview" aria-label="How a document will look">
        <div class="tb-lh-preview-label">How a document will look</div>
        <div class="tb-sheet">
          <table style="width:100%; border-collapse:collapse;">
            <tr>
              <td style="vertical-align:top; width:62%;">
                <table style="border-collapse:collapse;">
                  <tr>
                    @php($mark = $this->logoPreviewUrl() ?: ($currentLogo ? \Illuminate\Support\Facades\Storage::disk('public')->url($currentLogo) : null))
                    @if($mark)
                      <td style="padding-right:12px; vertical-align:top;">
                        <img class="tb-sheet-logo" src="{{ $mark }}" alt="">
                      </td>
                    @endif
                    <td style="vertical-align:top;">
                      <div class="tb-sheet-name">{{ $name ?: 'Your hospital' }}</div>
                      <div class="tb-sheet-lines">
                        @if($address){{ $address }}<br>@endif
                        @php($contact = array_filter([$phone, $email, $website]))
                        @if($contact){{ implode('  ·  ', $contact) }}<br>@endif
                        @if($registration){{ $registration }}@endif
                      </div>
                    </td>
                  </tr>
                </table>
              </td>
              <td style="vertical-align:top; text-align:right; width:38%;">
                <div class="tb-sheet-doc">INVOICE</div>
                <div class="tb-sheet-ref">INV-{{ now()->format('Y') }}-00042</div>
                <div class="tb-sheet-lines">Issued <b>{{ now()->format('d M Y') }}</b></div>
              </td>
            </tr>
          </table>
          <div class="tb-sheet-rule"></div>
          <div class="tb-sheet-body muted tb-small">The document’s own content sits here.</div>
          <div class="tb-sheet-foot">{{ $footer ?: 'Your footer line appears here.' }}</div>
        </div>

        <div class="tb-lh-save">
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save,logo">
            <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save letterhead</span>
            <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </aside>
    </div>
  </form>
</div>
