<x-dash.section title="Appointments by status (today)" icon="fa-calendar-day">
  <x-dash.bars :data="$byStatus" :labels="\App\Enums\AppointmentStatus::options()" />
</x-dash.section>
