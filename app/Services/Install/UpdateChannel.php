<?php

namespace App\Services\Install;

use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Asks true-doctor.online whether a newer release is available to this
 * licence, and fetches it (docs/proposals/standalone-edition.md §9).
 *
 * Optional by design: a clinic with no internet on its server uploads the
 * package from the buyer portal instead, and nothing about running the
 * system depends on this ever succeeding. Only the licence id and the version
 * are sent — no patient data, no usage figures.
 */
final class UpdateChannel
{
    public function __construct(private readonly InstalledLicence $licence) {}

    public function enabled(): bool
    {
        return filled(config('edition.update_server')) && $this->licence->isValid();
    }

    /**
     * The newest release this licence may install, or null when this is it.
     *
     * @return array{version:string,notes:?string,published_at:?string,size:?int,sha256:string,download_url:string}|null
     */
    public function check(): ?array
    {
        if (! $this->enabled()) {
            throw new \DomainException('Online update checks need a valid licence and an update server.');
        }

        $response = Http::timeout(15)->acceptJson()->get(rtrim((string) config('edition.update_server'), '/').'/standalone/updates', [
            'licence' => $this->licence->id(),
            'version' => Edition::version(),
        ]);

        if (! $response->successful()) {
            throw new \DomainException('The update server could not be reached ('.$response->status().'). You can upload an update file instead.');
        }

        $latest = $response->json('latest');
        Cache::put('standalone:update-check', ['checked_at' => now()->toIso8601String(), 'latest' => $latest], now()->addDays(2));

        if (! is_array($latest) || ! is_string($latest['version'] ?? null) || ! is_string($latest['download_url'] ?? null)) {
            return null;
        }

        return Edition::version() === 'dev' || version_compare($latest['version'], Edition::version(), '>') ? $latest : null;
    }

    /** The last answer, for the page, without asking again. @return array<string,mixed>|null */
    public function lastCheck(): ?array
    {
        $check = Cache::get('standalone:update-check');

        return is_array($check) ? $check : null;
    }

    /** Download a release into private storage and return its path. */
    public function download(string $url, string $sha256): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $server = parse_url((string) config('edition.update_server'), PHP_URL_HOST);
        if (! is_string($host) || $host !== $server || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \DomainException('The download address is not the update server.');
        }

        $target = storage_path('app/private/updates/incoming-'.bin2hex(random_bytes(6)).'.zip');
        File::ensureDirectoryExists(dirname($target), 0700);

        $response = Http::timeout(600)->withOptions(['sink' => $target])->get($url);
        if (! $response->successful() || ! is_file($target)) {
            @unlink($target);
            throw new \DomainException('The update could not be downloaded ('.$response->status().').');
        }
        if (! hash_equals(strtolower($sha256), (string) hash_file('sha256', $target))) {
            @unlink($target);
            throw new \DomainException('The downloaded file does not match its published checksum.');
        }

        return $target;
    }
}
