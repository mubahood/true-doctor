@props(['patient'])
{{-- Allergies, long-term conditions and a deceased status: in front of every
     clinical screen, so nobody prescribes, dispenses or reports blind. --}}
@if($patient)
  @php($alerts = \App\Support\Clinical\PatientAlerts::for($patient))
  <div class="clin-alerts tb-mb-3" role="region" aria-label="Clinical alerts for {{ $patient->full_name }}">
    @if($alerts['deceased'])
      <div class="tb-alert tb-alert-danger" role="alert"><i class="fas fa-user-slash" aria-hidden="true"></i><div><b>Deceased.</b> Check before acting on this record.</div></div>
    @endif
    @if($alerts['allergies'] !== [])
      <div class="tb-alert tb-alert-danger clin-allergy" role="alert">
        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
        <div><b>Allergies</b> @foreach($alerts['allergies'] as $allergy)<x-ui.badge tone="danger">{{ $allergy }}</x-ui.badge> @endforeach</div>
      </div>
    @elseif(! $alerts['allergies_recorded'])
      <div class="tb-alert tb-alert-warning" role="status">
        <i class="fas fa-circle-question" aria-hidden="true"></i>
        <div><b>Allergies not recorded.</b> Ask the patient, then record it on their record, even if it is "none".
          @can('update', $patient)<x-ui.link :href="route('admin.patients.show', $patient)">Open the record</x-ui.link>@endcan
        </div>
      </div>
    @else
      <div class="clin-none muted tb-xs"><i class="fas fa-circle-check" aria-hidden="true"></i> No known allergies.</div>
    @endif
    @if($alerts['medical_flags'] !== [])
      <div class="tb-alert tb-alert-warning" role="status">
        <i class="fas fa-hand" aria-hidden="true"></i>
        <div><b>Check before treatment</b> @foreach($alerts['medical_flags'] as $flag)<x-ui.badge tone="warn">{{ $flag }}</x-ui.badge> @endforeach</div>
      </div>
    @endif
    @if($alerts['conditions'] !== [])
      <div class="tb-alert" role="status">
        <i class="fas fa-heart-pulse" aria-hidden="true"></i>
        <div><b>Long-term conditions</b> @foreach($alerts['conditions'] as $condition)<x-ui.badge tone="info">{{ $condition }}</x-ui.badge> @endforeach</div>
      </div>
    @endif
  </div>
@endif
