<?php

namespace App\Livewire\Sync;

use App\Models\Device;
use App\Services\Sync\PullService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Am I ready to work without a connection?"
 *
 * `Sync\Index` is the fleet: which machines hold a copy, who is using them,
 * take one away. This is the opposite question, asked by one clinician about
 * the machine in front of them, before they walk out of signal.
 *
 * Almost everything on it is measured in the browser — whether the app is
 * downloaded, whether the records are here, how old they are, how much room
 * is left — because those are facts about this browser that no server can
 * know. What the SERVER contributes is the one thing the browser cannot know:
 * how much there is to download, so somebody can see the size of the job
 * before starting it on a phone tether.
 *
 * The page exists because of how both of the serious offline bugs were found:
 * not by a test, but by somebody switching the server off and discovering that
 * something everybody assumed worked had never worked once. A clinician should
 * not have to discover that in a ward with no signal.
 */
#[Layout('layouts.admin')]
class Readiness extends Component
{
    /** What this user's device would receive, so the size is known up front. */
    #[Computed]
    public function available(): array
    {
        return app(PullService::class)->availableFor(Auth::user());
    }

    /** This browser's row, if it has ever registered. Matched in the view by
     *  uuid, because the SERVER cannot tell which of these is "this one". */
    #[Computed]
    public function devices()
    {
        return Device::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('last_seen_at')
            ->get(['id', 'device_uuid', 'label', 'platform', 'last_sync_at', 'last_seen_at', 'revoked_at', 'revoked_reason']);
    }

    public function render()
    {
        return view('livewire.sync.readiness')->title('Offline readiness');
    }
}
