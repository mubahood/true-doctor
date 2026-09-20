{{-- Every kind of work still outstanding, not only the two with their own
     module. A dispensing nobody picked up holds a visit out of billing exactly
     as a lab order does (docs/orders.md). --}}
<x-dash.section title="Work outstanding" icon="fa-list-check"
                :count="$orders['total'] ?? 0"
                :href="route('admin.visits.index')" viewLabel="Visits">
  @if(($orders['total'] ?? 0) === 0)
    <x-dash.empty icon="fa-circle-check" text="Nothing is waiting" />
  @else
    <x-dash.bars :data="$orders['byType'] ?? []"
                 :labels="collect(\App\Enums\OrderType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" />

    @if(($orders['oldestDays'] ?? null) !== null && $orders['oldestDays'] > 0)
      <p class="dash-flow-note">
        <i class="fas fa-clock" aria-hidden="true"></i>
        The oldest has been waiting {{ $orders['oldestDays'] }}
        {{ Str::plural('day', $orders['oldestDays']) }}.
      </p>
    @endif
  @endif
</x-dash.section>
