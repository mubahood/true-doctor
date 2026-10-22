<div>
  {{-- Delete a visit opened by mistake (docs/visits.md, "Deleting a visit").
       Refused while money paid on it is still held: the reason says what to do. --}}
  <x-ui.modal size="sm" show="show" title="Delete this visit">
    @if($show && $visit)
      <form wire:submit="delete" class="vd-form">
        <div class="tb-modal-body">
          <p class="tb-small tb-mb-3">
            <span class="mono">{{ $visit->visit_no }}</span>, {{ $visit->patient?->full_name ?? 'Patient' }},
            opened {{ $visit->created_at->format('d M Y') }}.
          </p>

          @if($blocker)
            <div class="tb-alert tb-alert-warning tb-mb-3" role="alert">{{ $blocker }}</div>
          @else
            <ul class="vd-list tb-small tb-mb-4">
              @php($orders = $visit->orders->where('status', '!=', \App\Enums\OrderStatus::Cancelled)->count())
              @if($orders > 0)
                <li>{{ $orders }} {{ \Illuminate\Support\Str::plural('order', $orders) }} come off the bill, and anything taken off the shelf goes back.</li>
              @endif
              @foreach($visit->invoices->where('status', '!=', \App\Enums\InvoiceStatus::Void) as $invoice)
                <li>Invoice <span class="mono">{{ $invoice->invoice_no }}</span> is voided.</li>
              @endforeach
              <li>Dental chart entries made on this visit are removed.</li>
              <li>The visit leaves the lists, the reports and the patient's record. The audit log keeps who deleted it and why.</li>
            </ul>
            <x-ui.field label="Reason for deleting" for="vd-why" name="reason" required>
              <textarea id="vd-why" class="tb-textarea" rows="2" maxlength="255" wire:model="reason"
                        placeholder="Opened for the wrong patient, a duplicate, a test"></textarea>
            </x-ui.field>
          @endif
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="close">Keep the visit</button>
          @unless($blocker)
            <button type="submit" class="btn-tb btn-tb-danger" wire:loading.attr="disabled" wire:target="delete">
              <span wire:loading.remove wire:target="delete"><i class="fas fa-trash" aria-hidden="true"></i> Delete visit</span>
              <span wire:loading wire:target="delete">Deleting…</span>
            </button>
          @endunless
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
