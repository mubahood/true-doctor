{{-- One account, over the staff list.

     A role name is a label. What it LETS THEM DO is the question anybody
     auditing this list is asking, and reading it meant opening the seeder. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'User'">
  @if($this->peeked)
    @php($u = $this->peeked)
    @php($profile = $this->peekedProfile)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$u->name" :sub="$u->email">
        <x-ui.badge :tone="$u->is_active ? 'active' : 'danger'">{{ $u->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
        @if($u->id === auth()->id())<x-ui.badge tone="neutral">You</x-ui.badge>@endif
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Role', 'value' => $u->role_label],
        ['label' => 'Can do', 'value' => (string) count($this->peekedAbilities),
         'sub' => Str::plural('thing', count($this->peekedAbilities))],
        ['label' => 'Clinical profile', 'value' => $profile ? 'Yes' : 'No',
         'sub' => $profile?->department?->name],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Email</dt><dd>{{ $u->email }}</dd>
        <dt>Phone</dt><dd>{{ $u->phone ?: '—' }}</dd>
        <dt>Signing in</dt>
        <dd>
          @if($u->is_active)
            Allowed
          @else
            <span class="tb-peek-bad">Blocked — the account is switched off</span>
          @endif
        </dd>
        <dt>Added</dt><dd>{{ $u->created_at?->format('j M Y') ?? '—' }}</dd>
        @if($profile)
          <dt>Works in</dt><dd>{{ $profile->department?->name ?? '—' }}</dd>
          <dt>Specialty</dt><dd>{{ $profile->specialty ?: '—' }}</dd>
          <dt>Licence</dt><dd class="mono">{{ $profile->license_no ?: '—' }}</dd>
        @endif
      </dl>

      {{-- What the role actually carries. Listed rather than counted: "twelve
           permissions" is not something anybody can sign off. --}}
      <x-ui.peek-trail title="What this account may do" :rows="$this->peekedAbilities"
                       empty="No permissions at all — they can sign in and see nothing.">
        @foreach($this->peekedAbilities as $ability)
          <li wire:key="user-peek-can-{{ $ability }}">
            <span class="tb-peek-step mono">{{ $ability }}</span>
          </li>
        @endforeach
      </x-ui.peek-trail>
    </div>

    <x-ui.peek-foot>
      @can('update', $u)
        <button type="button" class="btn-tb btn-tb-primary" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit account
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
