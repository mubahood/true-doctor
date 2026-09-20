{{-- Where everybody in the building actually is (docs/visits.md). --}}
<x-dash.section title="Visits in progress" icon="fa-stethoscope"
                :count="$visits['open'] ?? 0"
                :href="route('admin.visits.index')" viewLabel="All visits">
  <x-dash.flow
    :blocked="$visits['blocked'] ?? 0"
    :steps="[
      [
        'label' => 'Not started',
        'count' => $visits['pending'] ?? 0,
        'href'  => route('admin.visits.index', ['status' => \App\Enums\VisitStatus::Pending->value]),
        'note'  => 'opened, nobody has begun',
      ],
      [
        'label' => 'In care',
        'count' => $visits['inCare'] ?? 0,
        'href'  => route('admin.visits.index', ['stage' => \App\Enums\VisitStage::Ongoing->value]),
        'note'  => 'being seen',
      ],
      [
        'label' => 'Billing',
        'count' => $visits['billing'] ?? 0,
        'href'  => route('admin.visits.index', ['stage' => \App\Enums\VisitStage::Billing->value]),
        'note'  => 'work done, bill to raise',
      ],
      [
        'label' => 'Payment',
        'count' => $visits['payment'] ?? 0,
        'href'  => route('admin.visits.index', ['stage' => \App\Enums\VisitStage::Payment->value]),
        'note'  => 'invoiced, awaiting payment',
        'tone'  => ($visits['payment'] ?? 0) > 0 ? 'warn' : '',
      ],
    ]" />
</x-dash.section>
