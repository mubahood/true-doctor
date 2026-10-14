<?php

namespace App\Support\Licensing;

use Illuminate\Support\Carbon;

/** Local, offline licence view. Invalid files never prevent clinical work. */
final class InstalledLicence
{
    /** @var array<string,mixed>|null */
    private ?array $payload = null;

    private bool $loaded = false;

    public function __construct(private readonly Verifier $verifier) {}

    public function isValid(): bool
    {
        $this->load();

        return $this->payload !== null;
    }

    public function id(): ?string
    {
        $this->load();

        return is_string($this->payload['id'] ?? null) ? $this->payload['id'] : null;
    }

    public function customer(): ?string
    {
        $this->load();

        return is_string($this->payload['customer'] ?? null) ? $this->payload['customer'] : null;
    }

    public function updatesUntil(): ?Carbon
    {
        $this->load();
        $date = $this->payload['updates_until'] ?? null;
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $date) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function limit(string $key): ?int
    {
        $this->load();
        $limits = $this->payload['limits'] ?? null;
        $value = is_array($limits) ? ($limits[$key] ?? null) : null;

        return is_int($value) && $value >= 0 ? $value : null;
    }

    public function matchesHost(string $host): bool
    {
        $this->load();
        $domains = $this->payload['domains'] ?? null;

        return is_array($domains) && in_array(
            strtolower($host),
            array_map('strtolower', array_filter($domains, 'is_string')),
            true,
        );
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $path = config('edition.licence_path');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            return;
        }

        try {
            $document = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($document) || ! $this->verifier->verifiesDocument($document)) {
                return;
            }
            $payload = $document['licence'];
            if (($payload['format'] ?? null) !== 1 || ($payload['product'] ?? null) !== 'standalone-hospital'
                || ! is_string($payload['id'] ?? null)) {
                return;
            }
            $this->payload = $payload;
        } catch (\Throwable) {
            // A damaged file becomes an administrator warning; care stays on.
        }
    }
}
