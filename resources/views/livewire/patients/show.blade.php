<div>
  <x-ui.page-header :title="$patient->full_name"
                    :crumbs="['Patients' => route('admin.patients.index'), $patient->patient_no => null]">
    <x-slot:actions>
      <x-ui.link :href="route('admin.patients.id-card', $patient)" :navigate="false"
                 target="_blank" rel="noopener" class="btn-tb">
        <i class="fas fa-id-card" aria-hidden="true"></i> ID card (PDF)
      </x-ui.link>
      @can('update', $patient)
        <x-ui.link :href="route('admin.patients.edit', $patient)" class="btn-tb btn-tb-primary">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit
        </x-ui.link>
      @endcan
      @can('delete', $patient)
        <button type="button" class="btn-tb btn-tb-danger" wire:click="archive" wire:confirm="Archive this patient?"
                wire:loading.attr="disabled" wire:target="archive">
          <i class="fas fa-box-archive" aria-hidden="true"></i> Archive
        </button>
      @endcan
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-detail-cols">
    {{-- ── Summary column (read-only) ─────────────────────────── --}}
    <div class="tb-stack">
      <div class="tb-card">
        <div class="tb-card-body tb-text-center">
          <div class="tb-fw-500">{{ $patient->full_name }}</div>
          <div class="mono tb-primary tb-mt-2">{{ $patient->patient_no }}</div>
          <div class="tb-mt-2">
            <x-ui.badge :tone="$patient->status->badge()">{{ $patient->status->label() }}</x-ui.badge>
          </div>
        </div>
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Identity</caption>
            <tbody>
              <tr><th scope="row">Sex</th><td>{{ $patient->sex?->label() ?? '—' }}</td></tr>
              <tr><th scope="row">Age</th><td>{{ $patient->age() !== null ? $patient->age().' yrs' : '—' }}</td></tr>
              <tr><th scope="row">Date of birth</th><td>{{ $patient->dob?->format('d M Y') ?? '—' }}</td></tr>
              <tr><th scope="row">Blood type</th><td>{{ $patient->blood_type ?? '—' }}</td></tr>
              <tr><th scope="row">District</th><td>{{ $patient->district?->name ?? '—' }}</td></tr>
              <tr><th scope="row">Registered</th><td>{{ $patient->created_at->format('d M Y') }}@if($patient->registeredBy) · {{ $patient->registeredBy->name }}@endif</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Contact</span></div>
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Contact</caption>
            <tbody>
              <tr><th scope="row">Phone</th><td>{{ $patient->phone_1 ?? '—' }}</td></tr>
              <tr><th scope="row">Alt phone</th><td>{{ $patient->phone_2 ?? '—' }}</td></tr>
              <tr><th scope="row">Email</th><td>{{ $patient->email ?? '—' }}</td></tr>
              <tr><th scope="row">Address</th><td>{{ $patient->address ?? '—' }}</td></tr>
              <tr><th scope="row">Home address</th><td>{{ $patient->home_address ?? '—' }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Medical</span></div>
        <div class="tb-card-body">
          <div class="tb-label">Allergies</div>
          <div class="tb-flex">
            @forelse(($patient->allergies ?? []) as $allergy)
              <x-ui.badge tone="warn">{{ $allergy }}</x-ui.badge>
            @empty
              <span class="muted tb-small">None recorded</span>
            @endforelse
          </div>
          <div class="tb-label tb-mt-4">Chronic conditions</div>
          <div class="tb-flex">
            @forelse(($patient->chronic_conditions ?? []) as $condition)
              <x-ui.badge tone="info">{{ $condition }}</x-ui.badge>
            @empty
              <span class="muted tb-small">None recorded</span>
            @endforelse
          </div>
        </div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Family &amp; emergency</span></div>
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Family and emergency contacts</caption>
            <tbody>
              <tr><th scope="row">Spouse</th><td>{{ $patient->spouse_name ?? '—' }}</td></tr>
              <tr><th scope="row">Father</th><td>{{ $patient->father_name ?? '—' }}</td></tr>
              <tr><th scope="row">Mother</th><td>{{ $patient->mother_name ?? '—' }}</td></tr>
              <tr><th scope="row">Emergency contact</th>
                <td>{{ $patient->emergency_contact_name ?? '—' }}@if($patient->emergency_contact_phone) · {{ $patient->emergency_contact_phone }}@endif</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Insurance on file</span></div>
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Insurance recorded on the patient record</caption>
            <tbody>
              <tr><th scope="row">Provider</th><td>{{ $patient->insurance_provider ?? '—' }}</td></tr>
              <tr><th scope="row">Member no.</th><td class="mono">{{ $patient->insurance_member_no ?? '—' }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Consent</span></div>
        <div class="tb-card-body">
          @if($patient->consent_given)
            <x-ui.badge tone="success">Consent given</x-ui.badge>
            <div class="muted tb-xs tb-mt-2">{{ $patient->consent_at?->format('d M Y H:i') ?? 'Date not recorded' }}</div>
          @else
            <x-ui.badge tone="danger">No consent recorded</x-ui.badge>
          @endif
        </div>
      </div>

      @if($patient->notes)
        <div class="tb-card">
          <div class="tb-card-header"><span class="tb-card-title">Notes</span></div>
          <div class="tb-card-body muted tb-small">{{ $patient->notes }}</div>
        </div>
      @endif
    </div>

    {{-- ── Workspace panels (each #[Lazy]) ────────────────────── --}}
    <div class="tb-stack">
      @can('manageCards', $patient)
        <livewire:patients.panels.cards :patient-id="$patient->id" :key="'cards-'.$patient->id" />
      @endcan

      <livewire:patients.panels.documents :patient-id="$patient->id" :key="'docs-'.$patient->id" />

      <livewire:patients.panels.dependents :patient-id="$patient->id" :key="'deps-'.$patient->id" />

      @canany(['insurance.view', 'insurance.manage'])
        <livewire:patients.panels.insurances :patient-id="$patient->id" :key="'ins-'.$patient->id" />
      @endcanany

      @canany(['treatments.view', 'treatments.manage'])
        <livewire:patients.panels.treatments :patient-id="$patient->id" :key="'treat-'.$patient->id" />
      @endcanany
    </div>
  </div>
</div>
