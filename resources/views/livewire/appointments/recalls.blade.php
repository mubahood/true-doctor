<div>
  <x-ui.page-header title="Recalls and reviews due" :crumbs="['Dashboard' => route('admin.dashboard'), 'Recalls and reviews due' => null]">
    <x-slot:explain>
      Patients to call or text: reviews booked for this week, reviews they missed, and dental check-ups due with nothing booked.
      Call with one tap. A text goes only to a patient who agreed to text messages, and only once for each thing due.
    </x-slot:explain>
  </x-ui.page-header>

  <div class="tb-ord-filters" role="tablist" aria-label="Which list">
    @foreach(\App\Services\FollowUpService::LISTS as $key => $label)
      <button type="button" role="tab" wire:key="list-{{ $key }}" wire:click="$set('show', '{{ $key }}')"
              aria-selected="{{ $shown === $key ? 'true' : 'false' }}" @class(['tb-chip', 'is-on' => $shown === $key])>
        {{ $label }} <b @class(['is-zero' => $counts[$key] === 0])>{{ $counts[$key] }}</b>
      </button>
    @endforeach
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search name, number or phone…" aria-label="Search patients">
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Patient</th><th>Phone</th><th>Due</th><th>With</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $row)
        @php
          $isRecall = $shown === 'recalls';
          $patient = $isRecall ? $row : $row->patient;
          $phone = $patient?->phone_1 ?: $patient?->phone_2;
        @endphp
        <tr wire:key="due-{{ $shown }}-{{ $row->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          <td>
            @if($patient)
              <x-ui.link :href="route('admin.patients.show', $patient)"><b class="tb-small">{{ $patient->full_name }}</b></x-ui.link>
              <div class="muted tb-xs mono">{{ $patient->patient_no }}</div>
            @else
              —
            @endif
          </td>
          <td class="tb-small mono tb-nowrap">{{ $phone ?? '—' }}</td>
          <td class="tb-small tb-nowrap">
            @if($isRecall)
              Dental check-up, {{ $row->next_recall_on?->format('d M Y') }}
            @else
              Review, {{ $row->scheduled_at->format('D d M, H:i') }}
            @endif
            {{-- Worded by FollowUpService::flag, as the app shows it. --}}
            @if($flag = $flags[$row->id] ?? null)<x-ui.badge :tone="$flag['tone']">{{ $flag['label'] }}</x-ui.badge>@endif
          </td>
          <td class="tb-small">{{ $isRecall ? '—' : ($row->doctor?->name ?? '—') }}</td>
          <td class="tb-text-right tb-nowrap">
            @if($phone)
              <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="btn-tb btn-tb-sm" aria-label="Call {{ $patient->full_name }}"><i class="fas fa-phone" aria-hidden="true"></i> Call</a>
            @endif
            @if($canText && $patient)
              <button type="button" class="btn-tb btn-tb-sm" wire:click="text('{{ $isRecall ? 'recall' : 'review' }}', {{ $row->id }})"
                      wire:loading.attr="disabled" wire:target="text" aria-label="Text {{ $patient->full_name }}">
                <i class="fas fa-comment-sms" aria-hidden="true"></i> Text
              </button>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty icon="fa-calendar-check" :filtered="$search !== ''" noun="patients" wire:click="$set('search', '')"
              :message="$search !== '' ? null : match ($shown) { 'missed' => 'Nobody missed a review.', 'recalls' => 'No dental check-up is due.', default => 'No review is booked for this week.' }" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}
</div>
