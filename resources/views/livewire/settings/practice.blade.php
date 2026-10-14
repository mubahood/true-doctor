<div>
  <x-ui.page-header title="Your practice">
    <x-slot:explain>What kind of practice this is decides what every setup page suggests — services and prices, rooms, wards, lab tests, stock, the chart of accounts — and the words offered when a clinician types. It also decides which parts of the system your menu shows. Changing it never removes anything you already have.</x-slot:explain>
  </x-ui.page-header>

  <div class="tb-card">
    <div class="tb-card-body">
      <livewire:onboarding.steps.practice :key="'settings-practice'" />
    </div>
  </div>
</div>
