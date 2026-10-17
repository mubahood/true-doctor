<span>
  @if($ready)
    <button type="button" @class(['btn-tb', 'btn-tb-sm btn-tb-ghost' => $small]) wire:click="open" wire:loading.attr="disabled" wire:target="open">
      <i class="fas fa-paper-plane" aria-hidden="true"></i> Send to patient
    </button>

    <x-ui.modal show="showShare" size="sm" autosaves :title="'Send the '.\Illuminate\Support\Str::lower($label)">
      <div class="tb-modal-body">
        @if($result)
          <div class="pl-result" role="status">
            <span class="tb-label">Link</span>
            <div class="pl-result-row">
              <input type="text" class="tb-input mono" readonly value="{{ $result['url'] }}" aria-label="Link to send" x-on:focus="$el.select()">
              <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" x-on:click="navigator.clipboard && navigator.clipboard.writeText(@js($result['url']))">
                <i class="fas fa-copy" aria-hidden="true"></i> Copy
              </button>
            </div>
            <span class="tb-label">Code</span>
            <div class="pl-code mono">{{ $result['code'] }}</div>
            <p class="muted tb-small">Shown once. The patient types it, or their date of birth, to open the link.</p>
            @if($result['message'])<p class="tb-small">{{ $result['message'] }}</p>@endif
          </div>
        @else
          <p class="muted tb-small">The patient opens it on their phone with a code, or their date of birth. After {{ \App\Models\PatientLink::MAX_ATTEMPTS }} wrong tries it locks.</p>
          <x-ui.field label="Link works for" for="pl-days" name="days">
            <select id="pl-days" class="tb-select" wire:model="days">
              @foreach($choices as $d)<option value="{{ $d }}">{{ $d }} {{ \Illuminate\Support\Str::plural('day', $d) }}</option>@endforeach
            </select>
          </x-ui.field>
          <label class="tb-check-group">
            <input type="checkbox" wire:model="sendSms" @disabled(! $canText)> Text the link and code to the patient
          </label>
          @if($textNote)<p class="muted tb-small">{{ $textNote }}</p>@endif
        @endif

        @if($existing->isNotEmpty())
          <div class="tb-peek-sec">Links already sent</div>
          <ul class="pl-existing">
            @foreach($existing as $link)
              @php($state = $link->state())
              <li wire:key="pl-ex-{{ $link->id }}">
                <x-ui.badge :tone="\App\Models\PatientLink::stateTone($state)">{{ \App\Models\PatientLink::stateLabel($state) }}</x-ui.badge>
                <span class="muted">{{ $link->created_at?->format('d M H:i') }} · {{ $link->creator?->name ?? 'staff' }} · opened {{ $link->opens_count }}×</span>
                @if($state === \App\Models\PatientLink::ACTIVE || $state === \App\Models\PatientLink::LOCKED)
                  <span class="pl-acts">
                    <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="revoke({{ $link->id }})"
                            wire:confirm="Withdraw this link? It stops working at once.">Withdraw</button>
                  </span>
                @endif
              </li>
            @endforeach
          </ul>
        @endif
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showShare', false)">Close</button>
        @unless($result)
          <button type="button" class="btn-tb btn-tb-primary" wire:click="share" wire:loading.attr="disabled" wire:target="share">
            <i class="fas fa-link" aria-hidden="true"></i> Make the link
          </button>
        @endunless
      </div>
    </x-ui.modal>
  @endif
</span>
