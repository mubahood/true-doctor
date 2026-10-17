@php($set = app(\App\Support\HospitalSettings::class))
@php($qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0')
<x-pdf.document
  title="Purchase order"
  :reference="$order->po_no"
  :meta="array_filter([
      'Date' => $order->order_date->format('d M Y'),
      'Expected by' => $order->expected_date?->format('d M Y'),
      'Status' => $order->status->label(),
  ])">

  <table class="party">
    <tr>
      <td style="width:62%;">
        <span class="k">Supplier</span>
        <span class="v big">{{ $order->supplier?->name }}</span>
        @if($order->supplier?->contact_person)<span class="v small">{{ $order->supplier->contact_person }}</span>@endif
        @if($order->supplier?->phone || $order->supplier?->email)
          <span class="v muted small">{{ collect([$order->supplier->phone, $order->supplier->email])->filter()->implode(' · ') }}</span>
        @endif
        @if($order->supplier?->address)<span class="v muted small">{{ $order->supplier->address }}</span>@endif
        @if($order->supplier?->tin)<span class="v muted small">TIN {{ $order->supplier->tin }}</span>@endif
      </td>
      <td style="width:38%;" class="r">
        <span class="k">Order total</span>
        <span class="headline">{{ $set->format($order->total) }}</span>
      </td>
    </tr>
  </table>

  <h2>Please supply</h2>

  <table class="rows">
    <thead><tr><th>#</th><th>Item</th><th>Unit</th><th class="r">Quantity</th><th class="r">Unit cost</th><th class="r">Total</th></tr></thead>
    <tbody>
      @foreach($order->items as $line)
        <tr>
          <td class="num">{{ $loop->iteration }}</td>
          <td>{{ $line->stockItem?->name }}</td>
          <td>{{ $line->stockItem?->unit }}</td>
          <td class="num r">{{ $qty($line->quantity) }}</td>
          <td class="num r">{{ $set->format($line->unit_cost) }}</td>
          <td class="num r">{{ $set->format($line->line_total) }}</td>
        </tr>
      @endforeach
      <tr><td colspan="5"><b>Total</b></td><td class="num r"><b>{{ $set->format($order->total) }}</b></td></tr>
    </tbody>
  </table>

  @if($order->notes)
    <h2>Notes</h2>
    <p>{{ $order->notes }}</p>
  @endif

  <p class="muted small">
    Please quote {{ $order->po_no }} on your delivery note and invoice, and print the batch number and expiry date on every pack.
    @if($order->supplier?->payment_terms_days !== null) Payment terms: {{ $order->supplier->payment_terms_days }} days from delivery. @endif
  </p>
  <p class="small">Ordered by: {{ ($order->orderedBy ?? $order->createdBy)?->name ?? '' }}</p>
</x-pdf.document>
