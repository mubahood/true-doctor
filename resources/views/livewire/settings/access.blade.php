<div>
  <x-ui.page-header title="Staff and access" :crumbs="['Dashboard' => route('admin.dashboard'), 'Staff and access' => null]">
    <x-slot:explain>
      What each role in your clinic may do. A tick changes it at once for everybody in that role, on the computer and in the app.
      The hospital admin always keeps everything, so the clinic can never be locked out. Every change is kept in the audit log.
    </x-slot:explain>
  </x-ui.page-header>

  @unless($canEdit)
    <div class="fn-note tb-mb-3"><i class="fas fa-lock" aria-hidden="true"></i> Only a hospital admin can change these. You can see them.</div>
  @endunless

  <div class="tb-card tb-mb-4">
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-user-lock" aria-hidden="true"></i> Roles and what they may do</span></div>
    <div class="tb-table-wrap">
      <table class="tb-table tb-access-table">
        <caption class="sr-only">Abilities by role</caption>
        <thead>
          <tr>
            <th scope="col">Ability</th>
            <th scope="col" class="tb-access-cell">Hospital admin</th>
            @foreach($roles as $role => $label)
              <th scope="col" class="tb-access-cell" wire:key="h-{{ $role }}">{{ $label }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @foreach(\App\Support\Access\Abilities::ALL as $key => $ability)
            <tr wire:key="ab-{{ $key }}">
              <th scope="row" class="tb-access-ability">
                <span class="tb-fw-500">{{ $ability['label'] }}</span>
                <span class="muted tb-xs">{{ $ability['hint'] }}</span>
              </th>
              <td class="tb-access-cell">
                <input type="checkbox" checked disabled aria-label="{{ $ability['label'] }}: hospital admin, always">
              </td>
              @foreach($roles as $role => $label)
                @php($cell = $matrix[$role][$key])
                <td @class(['tb-access-cell', 'is-changed' => $cell['changed']]) wire:key="c-{{ $role }}-{{ $key }}">
                  <input type="checkbox" @checked($cell['on']) @disabled(! $canEdit)
                         wire:click="toggle('{{ $role }}', '{{ $key }}')" wire:loading.attr="disabled" wire:target="toggle"
                         aria-label="{{ $ability['label'] }}: {{ $label }}">
                  @if($cell['partial'])<span class="muted tb-xs">partly</span>@endif
                </td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
        @if($canEdit)
          <tfoot>
            <tr>
              <td class="muted tb-xs">A marked box differs from the standard settings.</td>
              <td></td>
              @foreach($roles as $role => $label)
                <td class="tb-access-cell" wire:key="r-{{ $role }}">
                  @if(collect($matrix[$role])->contains(fn ($c) => $c['changed']))
                    <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="resetRole('{{ $role }}')" wire:loading.attr="disabled" wire:target="resetRole"
                            title="Put the {{ strtolower($label) }} role back to the standard settings">Standard</button>
                  @endif
                </td>
              @endforeach
            </tr>
          </tfoot>
        @endif
      </table>
    </div>
  </div>

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-user-plus" aria-hidden="true"></i> Extra abilities for one person</span></div>
    <div class="tb-card-body">
      <p class="muted tb-small tb-mb-3">For one person who needs more than their role, such as the senior nurse who also takes payments. Give them on the person's account in <x-ui.link :href="route('admin.users.index')">User accounts</x-ui.link>.</p>
      @forelse($people as $person)
        <div class="tb-access-person" wire:key="p-{{ $person->id }}">
          <span class="tb-fw-500">{{ $person->name }}</span>
          <span class="muted tb-xs">{{ $person->role_label }}</span>
          @foreach($extras[$person->id] ?? [] as $row)
            <x-ui.badge tone="info">{{ \App\Support\Access\Abilities::label($row->ability) }}</x-ui.badge>
          @endforeach
        </div>
      @empty
        <x-ui.empty compact icon="fa-user-plus" message="Nobody has extra abilities. Everybody has what their role allows." />
      @endforelse
    </div>
  </div>
</div>
