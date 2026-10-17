<?php

namespace App\Livewire\Super\Messaging;

use App\Livewire\Concerns\WithTable;
use App\Models\Hospital;
use App\Models\MessageCredit;
use App\Models\PatientMessage;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Message credit for every hospital (roadmap 05 §1): who has messages on,
 * their balance, what they sent this month, and a top-up once a clinic has
 * paid for a bundle (by mobile money, until bundles are sold through the
 * subscription checkout). Every top-up is a ledger posting with its reference.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithTable;

    public ?int $hospitalId = null;

    /** How many messages to add. Not `$messages`: Livewire keeps its validation messages under that name. */
    public ?int $quantity = null;

    public string $reference = '';

    public string $note = '';

    public function topUp(MessageCredits $credits): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
        $data = $this->validate([
            'hospitalId' => ['required', 'integer', 'exists:hospitals,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reference' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['hospitalId' => 'hospital', 'quantity' => 'messages', 'reference' => 'payment reference']);

        $credits->post((int) $data['hospitalId'], 'topup', (int) $data['quantity'], $data['reference'], null, Auth::id(), $data['note'] ?: null);
        $this->reset(['hospitalId', 'quantity', 'reference', 'note']);
        $this->dispatch('toast', type: 'success', message: 'Messages added.');
    }

    public function render(): View
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
        $credits = app(MessageCredits::class);

        $hospitals = Hospital::query()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')->paginate($this->perPage, ['id', 'name', 'settings']);

        $sent = PatientMessage::query()->withoutGlobalScopes()
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereIn('status', ['sent', 'delivered'])
            ->selectRaw('hospital_id, sum(segments) as n')->groupBy('hospital_id')->pluck('n', 'hospital_id');

        return view('livewire.super.messaging.index', [
            'hospitals' => $hospitals,
            'rows' => $hospitals->getCollection()->map(fn (Hospital $h) => [
                'hospital' => $h,
                'on' => MessagingSettings::of($h)->smsEnabled(),
                'balance' => $credits->balance($h->id),
                'sent' => (int) ($sent[$h->id] ?? 0),
            ]),
            'recent' => MessageCredit::query()->withoutGlobalScopes()->whereIn('entry', ['topup', 'grant'])->latest('id')->limit(15)->get(),
            'names' => Hospital::query()->pluck('name', 'id'),
            'available' => MessagingSettings::available(),
        ])->title('Message credit');
    }
}
