<?php

namespace App\Livewire\Super\Releases;

use App\Jobs\BuildStandaloneRelease;
use App\Models\StandaloneRelease;
use App\Services\Releases\StandaloneArchive;
use App\Services\StandaloneSalesService;
use App\Support\Licensing\Verifier;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\Process\Process;

#[Layout('layouts.admin')]
class Index extends Component
{
    public string $version = '';

    public string $notes = '';

    public function mount(): void
    {
        $last = StandaloneRelease::query()->orderByDesc('id')->first();
        $this->version = $last ? $this->nextVersion($last->version) : '1.0.0';
        // Since the last release that was actually built from a commit.
        $this->notes = $this->suggestedNotes(StandaloneRelease::query()
            ->whereIn('status', ['staged', 'published', 'yanked'])->whereNotNull('commit')
            ->orderByDesc('id')->value('commit'));
    }

    /**
     * What changed since the last release, from the commit subjects — which in
     * this repository are written as plain sentences, so they read as notes.
     */
    private function suggestedNotes(?string $since): string
    {
        $source = (string) config('edition.release_source');
        if (! is_dir($source.'/.git')) {
            return '';
        }

        $range = is_string($since) && preg_match('/^[a-f0-9]{40}$/', $since) ? $since.'..refs/heads/main' : 'refs/heads/main';
        $process = new Process(['git', 'log', '--no-merges', '-40', '--pretty=format:- %s', $range], $source);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }

    public function stage(): void
    {
        $this->validate([
            'version' => ['required', 'regex:/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/', 'unique:standalone_releases,version'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $test = config('edition.test_database');
        if (! app(StandaloneSalesService::class)->signingReady()
            || ! is_array($test) || empty($test['host']) || empty($test['user'])
            || ! is_dir((string) config('edition.release_source').'/.git')) {
            $this->addError('version', 'Release signing, an immutable main checkout, and an isolated MySQL proof account must be configured before staging.');

            return;
        }
        $release = StandaloneRelease::create([
            'version' => $this->version, 'notes' => trim($this->notes),
            'status' => 'queued', 'progress' => 'Waiting for the release worker',
        ]);
        BuildStandaloneRelease::dispatch($release->id)->onConnection('release-builds')->onQueue('releases');
        $this->version = $this->nextVersion($release->version);
        $this->dispatch('toast', message: 'Release queued. This page follows the build, which takes a few minutes.', type: 'success');
    }

    public function publish(int $id): void
    {
        $release = StandaloneRelease::findOrFail($id);
        if ($release->status !== 'staged' || $release->clean_install_passed_at === null
            || ! is_string($release->archive_path) || ! is_file($release->archive_path)
            || ! hash_equals((string) $release->sha256, (string) hash_file('sha256', $release->archive_path))) {
            $this->dispatch('toast', message: 'A release needs a verified archive and a passed clean install before it can be published.', type: 'error');

            return;
        }
        $keyId = config('edition.signing_key_id');
        $secret = base64_decode((string) config('edition.signing_secret'), true);
        if (! is_string($keyId) || $keyId === '' || $secret === false
            || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \DomainException('Release signing is not configured.');
        }
        (new StandaloneArchive)->verify($release->archive_path, new Verifier([
            $keyId => base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)),
        ]));
        $release->update(['status' => 'published', 'published_at' => now(), 'progress' => 'Published']);
        $this->dispatch('toast', message: "Release {$release->version} is published to buyers.", type: 'success');
    }

    public function retry(int $id): void
    {
        $release = StandaloneRelease::findOrFail($id);
        if ($release->status !== 'failed') {
            throw new \DomainException('Only a failed release can be retried.');
        }
        if (is_string($release->archive_path)) {
            @unlink($release->archive_path);
        }
        $release->update([
            'status' => 'queued', 'progress' => 'Waiting for the release worker',
            'failure' => null, 'archive_path' => null, 'sha256' => null,
            'file_count' => null, 'staged_at' => null, 'clean_install_passed_at' => null,
        ]);
        BuildStandaloneRelease::dispatch($release->id)->onConnection('release-builds')->onQueue('releases');
        $this->dispatch('toast', message: "Release {$release->version} queued again.", type: 'success');
    }

    public function yank(int $id): void
    {
        $release = StandaloneRelease::findOrFail($id);
        if ($release->status !== 'published') {
            throw new \DomainException('Only a published release can be withdrawn.');
        }
        $release->update(['status' => 'yanked', 'progress' => 'Withdrawn from downloads']);
        $this->dispatch('toast', message: "Release {$release->version} withdrawn from downloads.", type: 'success');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $test = config('edition.test_database');

        return view('livewire.super.releases.index', [
            'releases' => StandaloneRelease::query()->orderByDesc('id')->limit(30)->get(),
            // What staging needs, so a missing piece is a line on the page
            // rather than a failed build.
            'readiness' => [
                'Release signing key' => app(StandaloneSalesService::class)->signingReady(),
                'Clean-install proof database' => is_array($test) && filled($test['host'] ?? null) && filled($test['user'] ?? null),
                'Git checkout of main' => is_dir((string) config('edition.release_source').'/.git'),
            ],
        ])->title('Standalone releases');
    }

    private function nextVersion(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $parts)) {
            return $parts[1].'.'.$parts[2].'.'.((int) $parts[3] + 1);
        }

        return '1.0.0';
    }
}
