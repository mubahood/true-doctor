<?php

namespace App\Services\Releases;

use App\Services\Licensing\Signer;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Turns the commit at the tip of `main` into a signed buyer package
 * (docs/proposals/standalone-edition.md §6). Runs only on the SaaS build
 * worker, never on a customer installation.
 *
 * It releases what is running. The code comes from `git archive` of that one
 * commit, so nothing untracked — `.env`, `ssh.txt`, uploads, logs — can reach
 * a buyer. The built CSS and JavaScript are the ones committed with it (the
 * host has no Node, which is why they are committed: docs/deployment-cpanel.md).
 * The PHP dependencies are the production `vendor/` deploy.sh already installed
 * from the same `composer.lock`, copied rather than downloaded again; only when
 * the lock differs, or the installed copy has development packages in it, is
 * Composer run to install them fresh.
 */
final class StageStandaloneRelease
{
    /**
     * @param  callable(string):void  $progress
     * @return array{path:string,sha256:string,count:int,commit:string}
     */
    public function run(string $version, string $source, string $destination, callable $progress): array
    {
        $source = realpath($source) ?: '';
        if ($source === '' || ! is_dir($source.'/.git')) {
            throw new \RuntimeException('The release source must be a Git checkout on the build worker.');
        }
        $commit = trim($this->command(['git', 'rev-parse', '--verify', 'refs/heads/main^{commit}'], $source));
        if (! preg_match('/^[a-f0-9]{40}$/', $commit)) {
            throw new \RuntimeException('The main branch does not resolve to an immutable commit.');
        }
        $work = storage_path('app/private/release-builds/'.bin2hex(random_bytes(12)));
        File::ensureDirectoryExists($work, 0700);
        $stage = $work.'/source';
        File::ensureDirectoryExists($stage, 0700);

        try {
            $progress('Exporting main at '.substr($commit, 0, 7));
            $tar = $work.'/source.tar';
            $this->command(['git', 'archive', '--format=tar', '--output='.$tar, $commit], $source);
            $this->command(['tar', '-xf', $tar, '-C', $stage], $work);
            File::delete($tar);

            $progress('Preparing production PHP dependencies');
            $this->dependencies($source, $stage, $commit);

            // Committed with the code, exactly as deploy.sh publishes them.
            if (! is_file($stage.'/public/build/manifest.json')) {
                throw new \RuntimeException('This commit has no built assets (public/build). Run `npm run build`, commit the result, deploy, then stage again.');
            }

            $guide = $stage.'/resources/standalone/INSTALL.md';
            if (! is_file($guide)) {
                throw new \RuntimeException('This commit has no standalone installation guide (resources/standalone/INSTALL.md).');
            }
            // Who to ask: written in at build time from SupportContact, never
            // typed into the guide or the installer (they cannot read settings).
            $support = \App\Support\SupportContact::platform();
            File::put($stage.'/INSTALL.md', str_replace('{{support}}', self::supportLine($support), (string) File::get($guide)));
            File::put($stage.'/SUPPORT.json', json_encode([
                'whatsapp' => $support->whatsappDigits() ?: null,
                'whatsapp_display' => $support->whatsappDisplay() ?: null,
                'phone' => $support->phone() ?: null,
                'email' => $support->email() ?: null,
                'hours' => $support->hours() ?: null,
                'install_message' => $support->message('standalone.install', ['licence' => 'not yet installed']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            File::ensureDirectoryExists(dirname($destination), 0700);

            $progress('Signing and verifying the complete archive');
            $result = (new StandaloneArchive)->build($stage, $destination, $version, $commit, app(Signer::class));
            $progress('Archive verified: '.$result['count'].' files');

            return $result + ['commit' => $commit];
        } catch (\Throwable $exception) {
            File::delete($destination);
            throw $exception;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** The guide's "talk to us" line. */
    public static function supportLine(\App\Support\SupportContact $support): string
    {
        $ways = array_filter([
            $support->hasWhatsapp() ? 'WhatsApp **'.$support->whatsappDisplay().'** ('.$support->waLink('standalone.install', ['licence' => 'from my buyer portal']).')' : null,
            $support->phone() !== '' ? 'call **'.$support->phone().'**' : null,
            $support->email() !== '' ? 'email **'.$support->email().'**' : null,
        ]);

        return $ways === [] ? 'Contact True-Doctor from your buyer portal.' : 'Need help at any step? '.ucfirst(implode(', ', $ways)).'.'.($support->hours() !== '' ? ' '.$support->hours().'.' : '');
    }

    /**
     * The production `vendor/` for the staged commit.
     *
     * Reused from the running installation when it was installed from the very
     * same lock file without development packages — the normal case on the
     * SaaS host, because a release is built from what is deployed. Otherwise
     * installed by Composer, so a release can never carry a dev-only package
     * or a dependency the lock does not name.
     */
    private function dependencies(string $source, string $stage, string $commit): void
    {
        $lock = $stage.'/composer.lock';
        if (! is_file($lock)) {
            throw new \RuntimeException('This commit has no composer.lock.');
        }

        if ($this->reusableVendor($source, $lock)) {
            $this->command(['cp', '-R', $source.'/vendor', $stage.'/vendor'], $stage, 900);
            // The class maps Composer optimised were written for the source
            // directory; regenerate them for the package's own paths.
            $this->composer(['dump-autoload', '--no-dev', '--classmap-authoritative', '--no-interaction', '--no-scripts'], $stage, 300, false);

            return;
        }

        $this->composer(['install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--no-scripts', '--classmap-authoritative'], $stage, 1500, true);
    }

    private function reusableVendor(string $source, string $stagedLock): bool
    {
        $installed = $source.'/vendor/composer/installed.php';
        if (! is_file($source.'/vendor/autoload.php') || ! is_file($installed) || ! is_file($source.'/composer.lock')) {
            return false;
        }
        if (! hash_equals((string) hash_file('sha256', $source.'/composer.lock'), (string) hash_file('sha256', $stagedLock))) {
            return false;
        }

        $meta = require $installed;

        // A development install (`composer install` without --no-dev) has the
        // test and debugging packages in it; those never ship.
        return is_array($meta) && ($meta['root']['dev'] ?? true) === false;
    }

    /** @param list<string> $arguments */
    private function composer(array $arguments, string $directory, int $timeout, bool $required): void
    {
        $binary = $this->composerBinary();
        if ($binary === null) {
            if (! $required) {
                return;   // the copied autoloader still works, only less optimised
            }
            throw new \RuntimeException('Composer was not found on the build worker. Set RELEASE_COMPOSER to its path.');
        }

        $this->command(str_ends_with($binary, '.phar') || ! is_executable($binary)
            ? [PHP_BINARY, $binary, ...$arguments]
            : [$binary, ...$arguments], $directory, $timeout);
    }

    private function composerBinary(): ?string
    {
        $configured = config('edition.composer_binary');
        $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));

        foreach (array_filter([is_string($configured) ? $configured : null, $home !== '' ? $home.'/bin/composer' : null]) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $finder = new Process(['sh', '-c', 'command -v composer']);
        $finder->run();
        $found = trim($finder->getOutput());

        return $finder->isSuccessful() && $found !== '' ? $found : null;
    }

    /** @param list<string> $arguments */
    private function command(array $arguments, string $workingDirectory, int $timeout = 120): string
    {
        // Composer is commonly a /usr/bin/env php script. Use the same PHP
        // executable as this worker, rather than an unrelated host default.
        $path = dirname(PHP_BINARY).PATH_SEPARATOR.(string) getenv('PATH');
        $process = new Process($arguments, $workingDirectory, [
            'PATH' => $path,
            'COMPOSER_HOME' => storage_path('app/private/composer-home'),
            'HOME' => (string) (getenv('HOME') ?: storage_path('app/private')),
        ]);
        $process->setTimeout($timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException('Release build step failed: '.implode(' ', array_slice($arguments, 0, 2)).'. '.mb_substr($message, 0, 800));
        }

        return $process->getOutput();
    }
}
