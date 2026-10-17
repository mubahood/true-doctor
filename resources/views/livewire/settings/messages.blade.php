<div>
  <x-ui.page-header title="Messages to patients" :crumbs="['Dashboard' => route('admin.dashboard'), 'Messages' => null]">
    <x-slot:explain>
      Text messages from your clinic to your patients: the booking, a reminder the day before, the receipt, the dental check-up.
      A patient gets them only if they said yes at the desk. Each message shows here with what became of it.
    </x-slot:explain>
  </x-ui.page-header>

  @unless($available)
    <div class="fn-note is-warn tb-mb-3"><i class="fas fa-circle-info" aria-hidden="true"></i>
      Text messages are not switched on for this server yet. <x-support.link context="admin.help" :vars="['role' => auth()->user()->role, 'hospital' => auth()->user()->hospital?->name, 'screen' => 'patient text messages']" :icon="false">Ask us on WhatsApp</x-support.link>.
    </div>
  @endunless

  <div class="tb-stats-grid tb-mb-4" role="group" aria-label="Messages this month">
    @if($metered)
      <x-dash.stat icon="fa-coins" label="Messages left" :value="number_format($balance)" :tone="$balance < 50 ? 'warn' : 'ok'"
                   :sub="$balance < 50 ? 'Top up before reminders stop' : 'One message is one text of 160 letters'" />
    @endif
    <x-dash.stat icon="fa-paper-plane" label="Sent this month" :value="number_format((int) (($month['sent'] ?? 0) + ($month['delivered'] ?? 0)))" :sub="number_format((int) ($month['delivered'] ?? 0)).' delivered'" />
    <x-dash.stat icon="fa-triangle-exclamation" label="Failed or not sent" :value="number_format((int) (($month['failed'] ?? 0) + ($month['skipped'] ?? 0)))"
                 :tone="(($month['failed'] ?? 0) + ($month['skipped'] ?? 0)) > 0 ? 'warn' : ''" sub="See the reasons below" />
  </div>

  <form wire:submit="save" class="tb-card tb-mb-4">
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-comment-sms" aria-hidden="true"></i> What patients receive</span></div>
    <div class="tb-card-body">
      <label class="tb-check-group tb-mb-3">
        <input type="checkbox" wire:model.live="sms" @disabled(! $available)>
        <span><b>Send text messages to patients</b> who agreed to them</span>
      </label>

      <div @class(['msg-events', 'is-off' => ! $sms])>
        @foreach(\App\Services\Messaging\MessagingSettings::EVENTS as $event => [$label])
          <div class="msg-event" wire:key="ev-{{ $event }}">
            <label class="tb-check-group">
              <input type="checkbox" wire:model="events.{{ $event }}" @disabled(! $sms)>
              <span class="tb-fw-500">{{ $label }}</span>
            </label>
            <p class="msg-preview">{{ $previews[$event] ?? '' }}</p>
          </div>
        @endforeach
      </div>

      <div class="tb-form-grid tb-mt-3">
        <x-ui.field label="Quiet from" for="msg-quiet-start" name="quiet_start" hint="Nothing is sent between these hours; it waits until morning.">
          <input id="msg-quiet-start" type="time" wire:model="quiet_start" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Quiet until" for="msg-quiet-end" name="quiet_end">
          <input id="msg-quiet-end" type="time" wire:model="quiet_end" class="tb-input">
        </x-ui.field>
      </div>
    </div>
    <div class="tb-card-footer">
      <button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="save">Save</button>
      @if($metered)
        <span class="muted tb-xs msg-buy"><x-support.link context="admin.help" :vars="['role' => auth()->user()->role, 'hospital' => auth()->user()->hospital?->name, 'screen' => 'buying text messages']" :icon="false">Buy messages on WhatsApp</x-support.link></span>
      @endif
    </div>
  </form>

  <div class="tb-filter-bar tb-toolbar">
    <select wire:model.live="status" class="tb-select" aria-label="Which messages">
      <option value="">Every message</option>
      @foreach($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
    </select>
  </div>
  <div class="tb-card" wire:loading.class="tb-loading" wire:target="status"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>When</th><th>Patient</th><th>Message</th><th>To</th><th>Status</th></tr></thead>
    <tbody>
      @forelse($log as $m)
        <tr wire:key="msg-{{ $m->id }}">
          <td class="tb-nowrap tb-small">{{ $m->created_at?->format('d M H:i') }}</td>
          <td class="tb-small">{{ $m->patient?->full_name ?? '-' }}<div class="muted tb-xs">{{ \App\Services\Messaging\MessagingSettings::EVENTS[$m->event][0] ?? $m->event }}</div></td>
          <td class="tb-small msg-body">{{ $m->body }}</td>
          <td class="mono tb-xs tb-nowrap">{{ $m->to_phone ?? '-' }}</td>
          <td class="tb-small">
            <x-ui.badge :tone="$m->status->tone()">{{ $m->status->label() }}</x-ui.badge>
            @if($m->skip_reason)
              <div class="muted tb-xs">{{ match ($m->skip_reason) { 'no_consent' => 'The patient has not agreed to texts', 'no_phone' => 'No mobile number on the record', 'no_credit' => 'No messages left', 'event_off' => 'This message is switched off', default => $m->skip_reason } }}</div>
            @elseif($m->error)
              <div class="muted tb-xs">{{ $m->error }}</div>
            @elseif($m->send_after && $m->status === \App\Enums\MessageStatus::Queued)
              <div class="muted tb-xs">After the quiet hours, {{ $m->send_after->format('H:i') }}</div>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted">No messages yet.</td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  <div class="tb-mt-3">{{ $log->links() }}</div>
</div>
