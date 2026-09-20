<div class="dash-root">
  <x-ui.page-header title="Reports & dashboards" :crumbs="['Dashboard' => route('admin.dashboard'), 'Reports' => null]">
    <x-slot:explain>
      Everything in this page is read from the live records — payments, invoices,
      stay orders and the register. Figures marked “as at today” are a standing
      position and ignore the dates above them: what is owed, how many beds are
      full and what is on the shelf are true now, not for a period.
    </x-slot:explain>
    <x-slot:actions>
      {{-- Opens in its own tab, as every generated document in this system
           does: somebody printing a board paper still wants the screen they
           were reading when they come back. --}}
      <a class="btn-tb btn-tb-primary"
         href="{{ route('admin.reports.pdf', ['from' => $from, 'to' => $to]) }}"
         target="_blank" rel="noopener">
        <i class="fas fa-file-pdf" aria-hidden="true"></i> Print report
      </a>
    </x-slot:actions>
  </x-ui.page-header>

  {{-- The ranges that get asked for daily, beside the two pickers that can
       express any range at all. --}}
  @php($preset = $this->activePreset())
  <div class="tb-seg" role="group" aria-label="Report period">
    @foreach($this->presets() as $key => $label)
      <button type="button" class="tb-seg-btn {{ $preset === $key ? 'is-on' : '' }}"
              wire:click="usePreset('{{ $key }}')" @if($preset === $key) aria-pressed="true" @endif>
        {{ $label }}
      </button>
    @endforeach
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <x-ui.field label="From" for="rep-from" name="from">
      <input id="rep-from" type="date" wire:model.live="from" class="tb-input">
    </x-ui.field>
    <x-ui.field label="To" for="rep-to" name="to">
      <input id="rep-to" type="date" wire:model.live="to" class="tb-input">
    </x-ui.field>
    <div wire:loading.flex wire:target="from,to,usePreset" class="muted tb-small tb-flex">
      <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…
    </div>
  </div>

  {{-- ── What happened in the period ──────────────────────────────────── --}}
  <div class="tb-stats-grid">
    <x-dash.stat :value="\App\Support\HospitalSettings::money($this->revenue['total'])" icon="fa-coins" tone="ok"
      label="Revenue received" :sub="number_format($this->revenue['count']).' payments'" />
    <x-dash.stat :value="\App\Support\HospitalSettings::money($this->outstanding['total'])" icon="fa-file-invoice-dollar"
      :tone="bccomp($this->outstanding['buckets']['Over 90 days'], '0', 2) > 0 ? 'bad' : 'warn'"
      label="Outstanding" :sub="number_format($this->outstanding['count']).' unpaid invoices · as at today'"
      :href="route('admin.invoices.index')" />
    <x-dash.stat :value="number_format($this->inpatient['nights'])" icon="fa-bed" label="Inpatient nights"
      :sub="\App\Support\HospitalSettings::money($this->inpatient['revenue']).' billed'"
      :href="route('admin.admissions.index')" />
    <x-dash.stat :value="$this->occupancy['rate'].'%'" icon="fa-hospital" label="Bed occupancy"
      :tone="$this->occupancy['rate'] >= 90 ? 'warn' : ''"
      :sub="$this->occupancy['occupied'].' of '.$this->occupancy['total'].' beds · as at today'"
      :href="route('admin.admissions.board')" />
    <x-dash.stat :value="\App\Support\HospitalSettings::money($this->valuation['total_value'])" icon="fa-boxes-stacked"
      :tone="$this->valuation['low_stock'] > 0 ? 'warn' : ''"
      label="Stock value" :sub="$this->valuation['low_stock'].' low · '.$this->valuation['expiring'].' expiring'"
      :href="route('admin.stock.index')" />
    <x-dash.stat :value="number_format($this->demographics['total'])" icon="fa-user-injured" label="Patients"
      sub="on the register" :href="route('admin.patients.index')" />
  </div>

  {{-- The range picker earns its keep here: a total says how much, this says
       when — which day of the month the money actually arrived. --}}
  <x-dash.section title="Money received, day by day" icon="fa-chart-line">
    <x-dash.sparkline :series="$this->revenue['by_day']" :money="true" />
  </x-dash.section>

  {{-- `tops` so a short card keeps its own height rather than being stretched
       to whatever tall thing is beside it. Charts are paired with charts and
       tables with tables, so the two halves of a row end at roughly the same
       place of their own accord. --}}
  <div class="dash-grid cols-2 tops">
    <x-dash.section title="Revenue by method" icon="fa-wallet">
      <x-dash.bars :money="true"
        :labels="collect(\App\Enums\PaymentMethod::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all()"
        :data="$this->revenue['by_method']" />
    </x-dash.section>

    <x-dash.section title="Outstanding by age" icon="fa-hourglass-half" href="{{ route('admin.invoices.index') }}"
      view-label="Invoices">
      <x-dash.bars :money="true" :data="$this->outstanding['buckets']" />
      <div class="tb-card-body tb-small muted" style="padding-top:0;">
        A standing position, as at today — it does not move with the dates above.
      </div>
    </x-dash.section>

    <x-dash.section title="Top services by revenue" icon="fa-tags" :count="count($this->serviceRevenue)">
      <div class="tb-table-wrap"><table class="tb-table">
        <thead><tr><th>Service</th><th class="tb-text-right">Times</th><th class="tb-text-right">Charged</th></tr></thead>
        <tbody>
          @forelse(array_slice($this->serviceRevenue, 0, 10) as $r)
            <tr>
              <td>{{ $r['name'] }}</td>
              <td class="tb-text-right">{{ number_format($r['count']) }}</td>
              <td class="tb-text-right"><x-ui.money :amount="$r['revenue']" /></td>
            </tr>
          @empty
            <tr><td colspan="3"><x-dash.empty icon="fa-tags" text="Nothing billed in this period" /></td></tr>
          @endforelse
        </tbody>
      </table></div>
      @if(count($this->serviceRevenue) > 10)
        <div class="tb-card-body tb-small muted" style="padding-top:0;">
          The 10 largest of {{ number_format(count($this->serviceRevenue)) }}. The printed report lists 20.
        </div>
      @endif
    </x-dash.section>

    <x-dash.section title="Doctor activity" icon="fa-user-doctor">
      <div class="tb-table-wrap"><table class="tb-table">
        <thead><tr><th>Doctor</th><th class="tb-text-right">Visits</th><th class="tb-text-right">Appts</th></tr></thead>
        <tbody>
          @forelse($this->productivity as $r)
            <tr>
              <td>{{ $r['doctor'] }}</td>
              <td class="tb-text-right">{{ number_format($r['visits']) }}</td>
              <td class="tb-text-right">{{ number_format($r['appointments']) }}</td>
            </tr>
          @empty
            <tr><td colspan="3"><x-dash.empty icon="fa-user-doctor" text="No activity in this period" /></td></tr>
          @endforelse
        </tbody>
      </table></div>
    </x-dash.section>

    {{-- A stay is billed a night at a time now, so the ward figures are what
         was actually charged rather than a rate multiplied by a duration. --}}
    <x-dash.section title="Inpatient nights by ward" icon="fa-bed"
      href="{{ route('admin.admissions.index') }}" view-label="Admissions">
      <div class="tb-table-wrap"><table class="tb-table">
        <thead><tr><th>Ward</th><th class="tb-text-right">Nights</th><th class="tb-text-right">Billed</th></tr></thead>
        <tbody>
          @forelse($this->inpatient['by_ward'] as $r)
            <tr>
              <td>{{ $r['ward'] }}</td>
              <td class="tb-text-right">{{ number_format($r['nights']) }}</td>
              <td class="tb-text-right"><x-ui.money :amount="$r['revenue']" /></td>
            </tr>
          @empty
            <tr><td colspan="3"><x-dash.empty icon="fa-bed" text="Nobody was in a bed in this period" /></td></tr>
          @endforelse
        </tbody>
      </table></div>
      @if($this->inpatient['stays'] > 0)
        <div class="tb-card-body tb-small muted" style="padding-top:0;">
          {{ number_format($this->inpatient['stays']) }} stays touched this period,
          {{ number_format($this->inpatient['discharged']) }} discharged in it.
        </div>
      @endif
    </x-dash.section>

    <x-dash.section title="Patients on the register" icon="fa-chart-pie">
      <x-dash.bars :data="$this->demographics['by_sex']"
        :labels="['male' => 'Male', 'female' => 'Female', 'other' => 'Other', 'unknown' => 'Unknown']" />
      <x-dash.bars :data="$this->demographics['by_age']" />
    </x-dash.section>
  </div>
</div>
