<?php

namespace App\Livewire\Settings;

use App\Enums\MessageStatus;
use App\Models\Hospital;
use App\Models\PatientMessage;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\PatientMessenger;
use App\Support\CurrentHospital;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Settings → Messages: whether patients get text messages from this hospital,
 * which ones, its quiet hours, the message balance, and the log of every
 * message with what became of it (roadmap 05 §1, §3).
 */
#[Layout('layouts.admin')]
class Messages extends Component
{
    use WithPagination;

    public bool $sms = false;

    /** @var array<string,bool> */
    public array $events = [];

    public string $quiet_start = '21:00';

    public string $quiet_end = '07:00';

    #[Url(history: true)]
    public string $status = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
        $values = MessagingSettings::of($this->hospital())->toArray();
        $this->sms = $values['sms'];
        $this->events = $values['events'];
        $this->quiet_start = $values['quiet_start'];
        $this->quiet_end = $values['quiet_end'];
    }

    /** The clinic being configured; null for a platform account outside any hospital (it sees the defaults). */
    private function hospital(): ?Hospital
    {
        $id = app(CurrentHospital::class)->id();

        return $id !== null ? Hospital::query()->find($id) : null;
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
        $this->validate([
            'quiet_start' => ['required', 'date_format:H:i'],
            'quiet_end' => ['required', 'date_format:H:i'],
            'events' => ['array'],
        ]);

        $hospital = $this->hospital() ?? abort(404);
        MessagingSettings::save($hospital, [
            'sms' => $this->sms && MessagingSettings::available(),
            'events' => $this->events,
            'quiet_start' => $this->quiet_start,
            'quiet_end' => $this->quiet_end,
        ]);

        $this->dispatch('toast', type: 'success', message: $this->sms ? 'Saved. Patients who agreed will get these messages.' : 'Saved. No text messages go to patients.');
    }

    public function render(): View
    {
        $hospital = $this->hospital();
        /** @var LengthAwarePaginator<int, PatientMessage> $log */
        // With no hospital the tenant scope filters nothing: show nothing, never every clinic's log.
        $log = PatientMessage::query()->with('patient:id,first_name,last_name,patient_no')
            ->when($hospital === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest('id')->paginate(25);

        $month = PatientMessage::query()->when($hospital === null, fn ($q) => $q->whereRaw('1 = 0'))->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('livewire.settings.messages', [
            'available' => MessagingSettings::available(),
            'metered' => MessagingSettings::metered(),
            'balance' => $hospital !== null ? app(MessageCredits::class)->balance($hospital->id) : 0,
            'previews' => $this->previewsFor($hospital ?? new Hospital(['name' => (string) config('app.name')])),
            'log' => $log,
            'month' => $month,
            'statuses' => MessageStatus::cases(),
        ])->title('Messages to patients');
    }

    /** @return array<string,string> event => what the patient would read, with this clinic's own details */
    private function previewsFor(Hospital $hospital): array
    {
        $messenger = app(PatientMessenger::class);
        $samples = [
            'appointment_booked' => ['doctor' => 'Dr. Nakato', 'date' => now()->addDays(3)->format('D j M'), 'time' => '10:30'],
            'appointment_day_before' => ['time' => '10:30'],
            'receipt' => ['amount' => '20,000', 'invoice' => 'INV-2026-00123', 'link' => url('/s/r/…')],
            'dental_recall' => [],
        ];
        $out = [];
        foreach ($samples as $event => $vars) {
            $out[$event] = $messenger->body($event, $vars, $hospital);
        }

        return $out;
    }
}
