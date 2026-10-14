<div>
  <x-ui.page-header title="Finance settings">
    <x-slot:explain>How the hospital's own work lands in its books. Anything left on "Standard" uses the account shown. Changes apply from the next posting — nothing already in the books moves.</x-slot:explain>
  </x-ui.page-header>

  <form wire:submit="save">
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-hand-holding-dollar" aria-hidden="true"></i> Where payments land</span></div>
      <div class="tb-card-body">
        <p class="muted tb-small tb-mb-3">When a cashier takes a payment, the money goes into this book. A prepaid card spends a deposit already held, and an insurance settlement is money the insurer now owes — those are handled for you.</p>
        <div class="tb-form-grid">
          @foreach($methods as $method => $label)
            <x-ui.field :label="$label" for="fs-{{ $method }}" name="methodBooks.{{ $method }}">
              <select id="fs-{{ $method }}" wire:model="methodBooks.{{ $method }}" class="tb-select">
                <option value="">Standard — {{ $this->defaults['method.'.$method] }}</option>
                @foreach($this->books as $b)<option value="{{ $b->id }}">{{ $b->label() }}</option>@endforeach
              </select>
            </x-ui.field>
          @endforeach
        </div>
      </div>
    </div>

    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-wallet" aria-hidden="true"></i> Cards and insurers</span></div>
      <div class="tb-card-body">
        <div class="tb-form-grid">
          <x-ui.field label="Card top-ups without a payment method" for="fs-card" name="cardTopUpBook" hint="A top-up recorded with its method goes to that method's book.">
            <select id="fs-card" wire:model="cardTopUpBook" class="tb-select">
              <option value="">Standard — {{ $this->defaults['card'] }}</option>
              @foreach($this->books as $b)<option value="{{ $b->id }}">{{ $b->label() }}</option>@endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="Insurer deposits" for="fs-ins" name="insurerDepositBook" hint="Insurers usually pay by transfer.">
            <select id="fs-ins" wire:model="insurerDepositBook" class="tb-select">
              <option value="">Standard — {{ $this->defaults['insurer'] }}</option>
              @foreach($this->books as $b)<option value="{{ $b->id }}">{{ $b->label() }}</option>@endforeach
            </select>
          </x-ui.field>
        </div>
      </div>
    </div>

    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-truck-ramp-box" aria-hidden="true"></i> Stock deliveries</span></div>
      <div class="tb-card-body">
        <x-ui.field label="When stock is received, it is…" for="fs-stock" name="stockCreditAccount"
                    hint="Owed to suppliers: pay them later with Money out → Accounts payable. Paid on delivery: choose the book it is paid from.">
          <select id="fs-stock" wire:model="stockCreditAccount" class="tb-select">
            <option value="">Standard — owed to suppliers ({{ $this->defaults['stock'] }})</option>
            @foreach($this->creditAccounts as $a)<option value="{{ $a->id }}">{{ $a->is_book ? 'Paid from ' : 'Owed — ' }}{{ $a->label() }}</option>@endforeach
          </select>
        </x-ui.field>
      </div>
    </div>

    <div class="wz-actions">
      <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
        <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save settings</span>
        <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
      </button>
      <x-ui.link :href="route('admin.finance.journal')" class="btn-tb btn-tb-ghost">Opening balances are entered in the journal <i class="fas fa-arrow-right" aria-hidden="true"></i></x-ui.link>
    </div>
  </form>
</div>
