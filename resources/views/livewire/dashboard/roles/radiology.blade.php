<livewire:dashboard.section widget="radiology.stats" lazy />

<livewire:dashboard.section widget="radiology.queue" lazy />

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    <x-dash.quick :href="route('admin.radiology-orders.index')" icon="fa-x-ray" label="Radiology worklist" />
    <x-dash.quick :href="route('admin.radiology-studies.index')" icon="fa-radiation" label="Study catalogue" />
  </div>
</div>
