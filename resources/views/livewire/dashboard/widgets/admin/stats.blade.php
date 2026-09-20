@php($money = app(\App\Support\HospitalSettings::class))
{{--
  The five figures a day is read from.

  It used to open on the size of the patient REGISTER — a number that barely
  moves and that nobody acts on. A visit is the spine of this system: every
  order, charge and admission hangs off one, so "how much work is in the
  building" leads, and the register has moved down to the side stats where
  reference numbers belong.
--}}
<div class="tb-stats-grid">
  @can('visits.view')
    <x-dash.stat :value="number_format($visits['open'] ?? 0)" label="Open visits" icon="fa-stethoscope"
      :tone="($visits['blocked'] ?? 0) > 0 ? 'warn' : ''"
      :sub="($visits['blocked'] ?? 0) > 0
              ? ($visits['blocked'].' held up by open work')
              : (($visits['pending'] ?? 0).' not started')"
      :href="route('admin.visits.index')" />
  @endcan
  @can('appointments.view')
    <x-dash.stat :value="number_format($appointmentsToday ?? 0)" label="Appointments today" icon="fa-calendar-check"
      :sub="($awaitingStart ?? 0).' awaiting start'" :href="route('admin.appointments.queue')" />
  @endcan
  @can('ipd.view')
    <x-dash.stat :value="number_format($inpatients ?? 0)" label="Inpatients" icon="fa-bed"
      :sub="($occupancy['rate'] ?? 0).'% beds full'" :href="route('admin.admissions.board')" />
  @endcan
  @can('billing.view')
    <x-dash.stat :value="$money->format($revenueToday ?? '0')" label="Collected today" icon="fa-coins" tone="ok"
      :sub="($paymentsToday ?? 0).' '.Str::plural('payment', $paymentsToday ?? 0)" :href="route('admin.invoices.index')" />
    <x-dash.stat :value="$money->format($outstanding['total'] ?? '0')" label="Outstanding" icon="fa-file-invoice-dollar"
      :tone="($outstanding['count'] ?? 0) ? 'warn' : ''"
      :sub="($outstanding['count'] ?? 0).' unpaid '.Str::plural('invoice', $outstanding['count'] ?? 0)"
      :href="route('admin.invoices.index')" />
  @endcan
</div>
