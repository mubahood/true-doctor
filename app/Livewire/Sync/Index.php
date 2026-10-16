<?php

namespace App\Livewire\Sync;

use App\Livewire\Concerns\PeeksRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\Device;
use App\Models\SyncOperation;
use App\Models\SyncSession;
use App\Services\Sync\DeviceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Which machines hold a copy of this hospital's records, and what they have
 * been doing with it.
 *
 * Two audiences, one screen. An administrator asks "who has a copy, and can I
 * take it away from them?". Whoever is debugging a sync asks "what did that
 * device send, when, and what did we make of it?". Both questions are about
 * devices, so both live here.
 *
 * What is deliberately NOT here is the payload of anything. The operations
 * ledger stores a hash, not a record (plan §20, §27) — a clinical record in a
 * diagnostics screen is a second copy under nobody's governance, and it would
 * be readable by whoever can see this page rather than by whoever can see the
 * patient.
 *
 * @property-read Device|null $peeked
 * @property-read Collection<int,SyncOperation> $peekedOperations
 * @property-read Collection<int,SyncSession> $peekedSessions
 * @property-read array<string,int> $figures
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, PeeksRecords, WithTable;

    /** '' every device · 'active' · 'revoked' · 'stale' */
    #[Url(history: true, except: '')]
    public string $state = '';

    /** A device that has not been seen for this long has probably gone. */
    public const STALE_DAYS = DeviceService::STALE_DAYS;

    // ── Revoking ─────────────────────────────────────────────────────────
    public bool $showRevoke = false;

    public ?int $revokingId = null;

    public string $revoke_reason = '';

    public function mount(): void
    {
        $this->authorize('manage', Device::class);
    }

    protected function resetsPage(): array
    {
        return ['state'];
    }

    /**
     * @return array<string,int>
     */
    #[Computed]
    public function figures(): array
    {
        return app(DeviceService::class)->figures();
    }

    // ── Reading one device ───────────────────────────────────────────────

    protected function peekModel(): string
    {
        return Device::class;
    }

    protected function peekRelations(): array
    {
        return ['user', 'revokedBy'];
    }

    protected function peekCaches(): array
    {
        return ['peekedOperations', 'peekedSessions'];
    }

    protected function authorizePeek(Model $record): void
    {
        $this->authorize('manage', Device::class);
    }

    /**
     * The last few things this device sent.
     *
     * Columns chosen so the payload cannot be selected by accident: what
     * happened to an operation is diagnostics, what was in it is a patient
     * record.
     *
     * @return Collection<int,SyncOperation>
     */
    #[Computed]
    public function peekedOperations(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : SyncOperation::where('device_id', $this->peekId)
                ->latest('id')
                ->limit(12)
                ->get(['id', 'operation_id', 'entity', 'entity_uuid', 'operation', 'status', 'reason_code', 'message', 'processed_at', 'created_at']);
    }

    /** @return Collection<int,SyncSession> */
    #[Computed]
    public function peekedSessions(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : SyncSession::where('device_id', $this->peekId)->latest('id')->limit(6)->get();
    }

    // ── Revoking a device ────────────────────────────────────────────────

    public function openRevoke(int $deviceId): void
    {
        $this->authorize('manage', Device::class);

        $this->revokingId = $deviceId;
        $this->revoke_reason = '';
        $this->resetErrorBag();
        $this->showPeek = false;
        $this->showRevoke = true;
    }

    /**
     * Block a device and clear its copy.
     *
     * A reason is REQUIRED. The device shows it to whoever next opens it, and
     * "this device has been blocked" with no explanation is how a clinician
     * concludes the system is broken and starts writing on paper.
     *
     * The local wipe happens on the device's next contact, which means a
     * machine that never comes back keeps its copy — so revocation is a
     * control, not a guarantee, and the documentation says so (plan §17).
     */
    public function revoke(): void
    {
        $this->authorize('manage', Device::class);

        $this->validate([
            'revoke_reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'revoke_reason.required' => 'Say why. The device shows this to whoever next opens it.',
        ]);

        $device = Device::findOrFail($this->revokingId);

        app(DeviceService::class)->revoke($device, $this->revoke_reason, Auth::user());

        $this->showRevoke = false;
        $this->reset(['revokingId', 'revoke_reason']);
        unset($this->figures);

        $this->dispatch('toast', type: 'success', message: $device->label
            .' is blocked. Its copy is cleared the next time it reaches the server.');
    }

    /** Let a device back in — a laptop found, a member of staff returned. */
    public function restore(int $deviceId): void
    {
        $this->authorize('manage', Device::class);

        $device = Device::findOrFail($deviceId);
        app(DeviceService::class)->restore($device, Auth::user());

        unset($this->figures);
        $this->forgetPeeked();

        $this->dispatch('toast', type: 'success', message: $device->label.' may work offline again.');
    }

    public function render()
    {
        $this->authorize('manage', Device::class);

        $rows = app(DeviceService::class)->query($this->state, $this->search)->paginate($this->perPage);

        return view('livewire.sync.index', ['rows' => $rows])->title('Offline devices');
    }
}
