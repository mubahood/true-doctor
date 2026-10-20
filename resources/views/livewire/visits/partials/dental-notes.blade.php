{{--
  A dental visit written as simple notes (docs/dental.md, "Simple notes"):
  the presenting complaint, History and Oral examination as plain text, then
  the chart, the treatment done today, the treatment plan and the review. The
  chart and the plan are the dental tab's own, so nothing is kept twice.
--}}
@if(\App\Services\DentalCaseSheetService::canRead(auth()->user()))
  <livewire:visits.panels.dental-notes :visit-id="$visit->id" :key="'dn-'.$visit->id" />

  <livewire:patients.tabs.dental :patient-id="$visit->patient_id" :visit-id="$visit->id" part="chart" :key="'dn-chart-'.$visit->id" />

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Treatment done today</span></div>
    <div class="tb-card-body">
      <livewire:visits.panels.dental-follow-up :visit-id="$visit->id" part="done" :key="'dn-done-'.$visit->id" />
    </div>
  </div>

  <livewire:patients.tabs.dental :patient-id="$visit->patient_id" :visit-id="$visit->id" part="plan" :key="'dn-plan-'.$visit->id" />

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Follow-up</span></div>
    <div class="tb-card-body">
      <livewire:visits.panels.dental-follow-up :visit-id="$visit->id" part="review" :key="'dn-review-'.$visit->id" />
    </div>
  </div>
@else
  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-tooth" aria-hidden="true"></i> Dental notes</span></div>
    <div class="tb-card-body"><p class="muted tb-small">The dentist's notes are not shown to your role. Ask your hospital admin if you need them.</p></div>
  </div>
@endif
