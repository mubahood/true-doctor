<?php

namespace App\Livewire\System;

use App\Services\Install\InstallationBackups;
use App\Services\Install\ServerHealth;
use App\Services\Install\StandaloneUpdater;
use App\Services\Install\SystemState;
use App\Services\Install\UpdateChannel;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings → Licence & updates, the standalone owner's view of their own
 * server (docs/proposals/standalone-edition.md §9): which release is running,
 * what the licence covers, whether the scheduler and backups are alive, and
 * the way to a newer release.
 *
 * Nothing slow happens in this component. An update or a backup is left as a
 * request (SystemState) for the scheduler, and the page follows its progress.
 *
 * @property-read array<string,mixed> $state
 * @property-read list<array{name:string,size:int,created_at:int}> $backups
 * @property-read list<array{name:string,size:int}> $waitingFiles
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    /** @var array<string,mixed>|null the last online check, for this visit */
    public ?array $available = null;

    public ?string $checkError = null;

    public function mount(): void
    {
        $this->authorizeOwner();
        $this->available = app(ServerHealth::class)->availableUpdate();
    }

    private function authorizeOwner(): void
    {
        abort_unless(Edition::isStandalone(), 404);
        abort_unless(Auth::user()?->can('manage-settings'), 403);
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function state(): array
    {
        return app(SystemState::class)->current();
    }

    /** @return list<array{name:string,size:int,created_at:int}> */
    #[Computed]
    public function backups(): array
    {
        return app(InstallationBackups::class)->list();
    }

    /**
     * Update packages put in the updates folder with cPanel File Manager — for
     * hosts whose upload limit is smaller than a release.
     *
     * @return list<array{name:string,size:int}>
     */
    #[Computed]
    public function waitingFiles(): array
    {
        $files = glob(storage_path('app/private/updates/*.zip')) ?: [];

        return array_values(array_map(fn ($f) => ['name' => basename($f), 'size' => (int) filesize($f)],
            array_filter($files, fn ($f) => ! str_starts_with(basename($f), 'incoming-'))));
    }

    public function checkNow(UpdateChannel $channel): void
    {
        $this->authorizeOwner();
        $this->checkError = null;

        try {
            $this->available = $channel->check();
            $this->dispatch('toast', message: $this->available ? 'True-Doctor '.$this->available['version'].' is available.' : 'You are on the newest release your licence covers.', type: 'success');
        } catch (\DomainException $e) {
            $this->checkError = $e->getMessage();
        }
    }

    public function installOnline(SystemState $state): void
    {
        $this->authorizeOwner();
        $this->queue($state, ['action' => 'update', 'source' => 'online']);
    }

    public function installFile(string $name, StandaloneUpdater $updater, SystemState $state): void
    {
        $this->authorizeOwner();
        $path = storage_path('app/private/updates/'.basename($name));
        if (! preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name) || ! is_file($path)) {
            $this->dispatch('toast', message: 'That file is no longer in the updates folder.', type: 'error');

            return;
        }

        try {
            $checked = $updater->inspect($path);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->queue($state, ['action' => 'update', 'source' => 'file', 'archive' => $path, 'version' => $checked['version']]);
    }

    public function backupNow(SystemState $state): void
    {
        $this->authorizeOwner();
        $this->queue($state, ['action' => 'backup']);
    }

    /** @param array<string,mixed> $request */
    private function queue(SystemState $state, array $request): void
    {
        try {
            $state->request($request + ['by' => Auth::id()]);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->state);
        $this->dispatch('toast', message: 'Queued — the scheduler starts it within a minute.', type: 'success');
    }

    public function render()
    {
        $this->authorizeOwner();
        $licence = app(InstalledLicence::class);
        $health = app(ServerHealth::class)->health();

        return view('livewire.system.index', [
            'version' => Edition::version(),
            'installed' => $health['installed'],
            'licence' => $licence,
            'hostMatches' => $licence->isValid() && $licence->matchesHost(request()->getHost()),
            'heartbeat' => $health['heartbeat'],
            'queued' => $health['queued'],
            'diskFree' => $health['diskFree'],
            'uploadLimit' => $health['uploadLimit'],
            'backupAge' => $health['backupAge'],
            'busy' => in_array($this->state['status'] ?? null, ['queued', 'running'], true),
        ])->title('Licence & updates');
    }
}
