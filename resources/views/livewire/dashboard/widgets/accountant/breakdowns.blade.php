@php $invLabels = collect(\App\Enums\InvoiceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(); @endphp
<div class="dash-grid cols-2">
  <x-dash.section title="Revenue by method (this month)" icon="fa-wallet">
    <x-dash.bars :data="$byMethod" :money="true" />
  </x-dash.section>
  <x-dash.section title="Invoices by status" icon="fa-file-invoice">
    <x-dash.bars :data="$byStatus" :labels="$invLabels" />
  </x-dash.section>
</div>
