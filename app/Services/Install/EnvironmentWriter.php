<?php

namespace App\Services\Install;

use Dotenv\Dotenv;

/** Creates the first environment file after testing every quoted value. */
final class EnvironmentWriter
{
    /** @param array<string,string> $settings */
    public function write(string $path, array $settings): void
    {
        if (is_file(dirname($path).'/storage/app/private/installed.json')) {
            throw new \DomainException('This installation is already complete.');
        }

        $lines = [];
        foreach ($settings as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('An environment setting has an invalid name or value.');
            }
            $lines[] = $key.'='.$this->quote($value);
        }

        $contents = implode("\n", $lines)."\n";
        $parsed = Dotenv::parse($contents);
        foreach ($settings as $key => $value) {
            if (($parsed[$key] ?? null) !== $value) {
                throw new \InvalidArgumentException("The {$key} value cannot be safely written to the environment file.");
            }
        }

        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('The application directory is not writable.');
        }
        chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            unlink($temporary);
            throw new \RuntimeException('The environment file could not be saved.');
        }
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(
            ['\\', '"', '$', "\n", "\r"],
            ['\\\\', '\\"', '\\$', '\\n', '\\r'],
            $value,
        ).'"';
    }
}
