{{--
  The dental case sheet on a dental visit (docs/dental.md, "The case sheet"),
  in the order a dentist works: History, Examination (soft tissues,
  investigations, then the chart as the hard tissues), Diagnosis, Treatment
  done today, Plan and follow-up. Each part is its own small component; the
  chart and the plan are the dental tab's own, so nothing is kept twice.
--}}
@if(\App\Services\DentalCaseSheetService::canRead(auth()->user()))
  <div class="tb-card cs-sheet">
    <div class="tb-card-header">
      <span class="tb-card-title"><i class="fas fa-tooth" aria-hidden="true"></i> Dental consultation · History</span>
      <div class="tb-flex">
        <a href="{{ route('admin.visits.report', $visit) }}" target="_blank" rel="noopener" class="btn-tb btn-tb-sm btn-tb-ghost"><i class="fas fa-file-pdf" aria-hidden="true"></i> Case sheet</a>
      </div>
    </div>
    <div class="tb-card-body cs-body">
      @foreach(['complaint', 'dental_history', 'medical', 'surgical'] as $section)
        <livewire:visits.panels.case-sheet-section :visit-id="$visit->id" :section="$section" :key="'cs-'.$section.'-'.$visit->id" />
      @endforeach
      @can('viewBill', $visit)
        <livewire:visits.panels.case-sheet-section :visit-id="$visit->id" section="financial" :key="'cs-financial-'.$visit->id" />
      @endcan
    </div>
  </div>

  <div class="tb-card cs-sheet">
    <div class="tb-card-header"><span class="tb-card-title">Examination</span></div>
    <div class="tb-card-body cs-body">
      <livewire:visits.panels.case-sheet-section :visit-id="$visit->id" section="extra_oral" :key="'cs-extra-'.$visit->id" />
      <livewire:visits.panels.case-sheet-section :visit-id="$visit->id" section="intra_oral" :key="'cs-intra-'.$visit->id" />
      <livewire:visits.panels.dental-xrays :visit-id="$visit->id" :key="'cs-xrays-'.$visit->id" />
    </div>
  </div>

  {{-- Hard tissues: the chart itself. --}}
  <livewire:patients.tabs.dental :patient-id="$visit->patient_id" :visit-id="$visit->id" part="chart" :key="'cs-chart-'.$visit->id" />

  <div class="tb-card cs-sheet">
    <div class="tb-card-header"><span class="tb-card-title">Diagnosis</span><span class="muted tb-xs">Coded for the Ministry reports</span></div>
    <div class="tb-card-body cs-body">
      <livewire:visits.panels.dental-diagnoses :visit-id="$visit->id" :key="'cs-dx-'.$visit->id" />
    </div>
  </div>

  <div class="tb-card cs-sheet">
    <div class="tb-card-header"><span class="tb-card-title">Treatment done today</span></div>
    <div class="tb-card-body cs-body">
      <livewire:visits.panels.dental-follow-up :visit-id="$visit->id" part="done" :key="'cs-done-'.$visit->id" />
      <livewire:visits.panels.case-sheet-section :visit-id="$visit->id" section="treatment_notes" :key="'cs-tn-'.$visit->id" />
    </div>
  </div>

  {{-- Plan: the treatment plan and the next check-up. --}}
  <livewire:patients.tabs.dental :patient-id="$visit->patient_id" :visit-id="$visit->id" part="plan" :key="'cs-plan-'.$visit->id" />

  <div class="tb-card cs-sheet">
    <div class="tb-card-header">
      <span class="tb-card-title">Follow-up</span>
      <a href="{{ route('admin.visits.dental-instructions', $visit) }}" target="_blank" rel="noopener" class="btn-tb btn-tb-sm btn-tb-ghost"><i class="fas fa-print" aria-hidden="true"></i> Print instructions</a>
    </div>
    <div class="tb-card-body cs-body">
      <livewire:visits.panels.case-sheet-section :visit-id="$visit->id" section="instructions" :key="'cs-ins-'.$visit->id" />
      <livewire:visits.panels.dental-follow-up :visit-id="$visit->id" part="review" :key="'cs-review-'.$visit->id" />
    </div>
  </div>
@else
  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-tooth" aria-hidden="true"></i> Dental consultation</span></div>
    <div class="tb-card-body"><p class="muted tb-small">The dentist's notes are not shown to your role. Ask your hospital admin if you need them.</p></div>
  </div>
@endif
