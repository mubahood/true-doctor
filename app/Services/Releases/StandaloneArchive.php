<?php

namespace App\Services\Releases;

use App\Services\Licensing\Signer;
use App\Support\Licensing\Canonical;
use App\Support\Licensing\Verifier;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/** A signed, deliberately narrow archive layout for shared hosting. */
final class StandaloneArchive
{
    /**
     * What entries extract as. Every file is 0644: a string added from memory
     * (PACKAGE.json, the signed file list) otherwise extracts 0666, writable by
     * anybody. The directories added here are exactly the ones PHP must write
     * (storage/…, bootstrap/cache), and the package cannot know which user PHP
     * runs as on a buyer's server — so they stay 0777, as they always were,
     * and the installer's writable check covers the rest.
     */
    private const DIRECTORY_MODE = 040777;

    private const FILE_MODE = 0100644;

    private const APP_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'vendor'];

    private const APP_FILES = ['artisan', 'composer.json', 'composer.lock', 'RELEASE.json', 'RELEASE_KEYS.json', 'INSTALL.md'];

    private const DENIED_NAMES = ['.env', 'ssh.txt', 'id_rsa', 'id_ed25519', 'database.sqlite', 'auth.json'];

    private const REQUIRED_DIRECTORIES = [
        'true_doctor_app/storage/', 'true_doctor_app/storage/app/', 'true_doctor_app/storage/app/private/',
        'true_doctor_app/storage/app/public/',
        'true_doctor_app/storage/framework/', 'true_doctor_app/storage/framework/cache/',
        'true_doctor_app/storage/framework/cache/data/', 'true_doctor_app/storage/framework/sessions/',
        'true_doctor_app/storage/framework/views/', 'true_doctor_app/storage/logs/',
        'true_doctor_app/bootstrap/cache/',
    ];

    /**
     * @return array{path:string,sha256:string,count:int}
     */
    public function build(string $stagedRoot, string $destination, string $version, string $commit, Signer $signer): array
    {
        if (! preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/', $version)
            || ! preg_match('/^[a-f0-9]{40}$/', $commit)) {
            throw new \InvalidArgumentException('A release needs a semantic version and a full immutable commit.');
        }
        if (! is_file($stagedRoot.'/vendor/autoload.php') || ! is_file($stagedRoot.'/public/build/manifest.json')) {
            throw new \RuntimeException('Composer dependencies and built assets must be present before packing.');
        }

        $release = ['version' => $version, 'commit' => $commit, 'edition' => 'standalone', 'built_at' => gmdate('c')];
        file_put_contents($stagedRoot.'/RELEASE.json', Canonical::json($release)."\n");
        $keys = $this->trustedKeys();
        file_put_contents($stagedRoot.'/RELEASE_KEYS.json', Canonical::json($keys)."\n");

        $files = $this->collect($stagedRoot);
        $manifest = [
            'format' => 1,
            'product' => 'standalone-hospital',
            'version' => $version,
            'commit' => $commit,
            'directories' => self::REQUIRED_DIRECTORIES,
            'files' => array_map(
                static fn (string $source): array => ['sha256' => hash_file('sha256', $source), 'size' => filesize($source)],
                $files,
            ),
        ];
        $signed = ['manifest' => $manifest] + $signer->sign($manifest);

        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The release archive could not be created.');
        }
        try {
            foreach (self::REQUIRED_DIRECTORIES as $directory) {
                if (! $zip->addEmptyDir($directory)) {
                    throw new \RuntimeException("Could not add {$directory} to the release.");
                }
                $zip->setMtimeName($directory, 315532800);
                $zip->setExternalAttributesName($directory, ZipArchive::OPSYS_UNIX, self::DIRECTORY_MODE << 16);
            }
            foreach ($files as $path => $source) {
                if (! $zip->addFile($source, $path)) {
                    throw new \RuntimeException("Could not add {$path} to the release.");
                }
                $zip->setMtimeName($path, 315532800);
                $zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, self::FILE_MODE << 16);
            }
            $zip->addFromString('PACKAGE.json', Canonical::json($signed)."\n");
            $zip->setMtimeName('PACKAGE.json', 315532800);
            $zip->setExternalAttributesName('PACKAGE.json', ZipArchive::OPSYS_UNIX, self::FILE_MODE << 16);
        } finally {
            $zip->close();
        }

        $this->verify($destination, new Verifier($keys));

        return ['path' => $destination, 'sha256' => hash_file('sha256', $destination), 'count' => count($files)];
    }

    /** Verify exact paths and hashes before extraction. */
    public function verify(string $archive, Verifier $verifier): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('The release archive cannot be opened.');
        }
        try {
            $signed = json_decode((string) $zip->getFromName('PACKAGE.json'), true, 64, JSON_THROW_ON_ERROR);
            $manifest = $signed['manifest'] ?? null;
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1
                || ($manifest['product'] ?? null) !== 'standalone-hospital'
                || ($manifest['directories'] ?? null) !== self::REQUIRED_DIRECTORIES
                || ! is_array($manifest['files'] ?? null)
                || ! $verifier->verifies($manifest, (string) ($signed['signature'] ?? ''), (string) ($signed['key_id'] ?? ''))) {
                throw new \RuntimeException('The release signature is invalid.');
            }

            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $path = $entry['name'] ?? '';
                $isDirectory = str_ends_with($path, '/');
                if (! ($isDirectory ? in_array($path, self::REQUIRED_DIRECTORIES, true) : $this->safePath($path))
                    || isset($seen[$path])) {
                    throw new \RuntimeException('The archive contains an unsafe or duplicate path.');
                }
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && ((($attributes >> 16) & 0170000) === 0120000)) {
                    throw new \RuntimeException('The archive contains a symbolic link.');
                }
                $seen[$path] = true;
                if ($isDirectory) {
                    if ($entry['size'] !== 0) {
                        throw new \RuntimeException('An archive directory contains data.');
                    }

                    continue;
                }
                if ($path === 'PACKAGE.json') {
                    continue;
                }
                $expected = $manifest['files'][$path] ?? null;
                if (! is_array($expected) || ($expected['size'] ?? null) !== $entry['size']
                    || $entry['size'] > 100 * 1024 * 1024) {
                    throw new \RuntimeException("The archive file list does not match: {$path}.");
                }
                $stream = $zip->getStream($path);
                if ($stream === false) {
                    throw new \RuntimeException("Cannot read {$path}.");
                }
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                if (! hash_equals((string) ($expected['sha256'] ?? ''), hash_final($hash))) {
                    throw new \RuntimeException("The archive hash does not match: {$path}.");
                }
            }
            if (count($seen) !== count($manifest['files']) + count(self::REQUIRED_DIRECTORIES) + 1) {
                throw new \RuntimeException('The archive is missing a listed file.');
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /**
     * Verify a package after File Manager has moved web_root_files into the
     * domain's document root. This runs before the installer writes anything.
     */
    public function verifyExtracted(string $appRoot, string $webRoot, Verifier $verifier): array
    {
        $document = dirname($appRoot).'/PACKAGE.json';
        if (! is_file($document)) {
            throw new \RuntimeException('PACKAGE.json was not found beside true_doctor_app. Extract the complete ZIP in your account home folder.');
        }
        $signed = json_decode((string) file_get_contents($document), true, 64, JSON_THROW_ON_ERROR);
        $manifest = $signed['manifest'] ?? null;
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1
            || ($manifest['product'] ?? null) !== 'standalone-hospital'
            || ($manifest['directories'] ?? null) !== self::REQUIRED_DIRECTORIES
            || ! is_array($manifest['files'] ?? null)
            || ! $verifier->verifies($manifest, (string) ($signed['signature'] ?? ''), (string) ($signed['key_id'] ?? ''))) {
            throw new \RuntimeException('The extracted release manifest signature is invalid.');
        }
        if (count($manifest['files']) > 100000) {
            throw new \RuntimeException('The release contains too many files.');
        }
        foreach (self::REQUIRED_DIRECTORIES as $directory) {
            $source = $appRoot.'/'.substr($directory, strlen('true_doctor_app/'));
            if (! is_dir($source) || is_link($source)) {
                throw new \RuntimeException("An extracted release directory is missing: {$directory}.");
            }
        }
        foreach ($manifest['files'] as $path => $expected) {
            if (! is_string($path) || ! $this->safePath($path) || ! is_array($expected)) {
                throw new \RuntimeException('The extracted release manifest contains an unsafe path.');
            }
            $source = str_starts_with($path, 'true_doctor_app/')
                ? $appRoot.'/'.substr($path, strlen('true_doctor_app/'))
                : $webRoot.'/'.substr($path, strlen('web_root_files/'));
            if (! is_file($source) || is_link($source) || filesize($source) !== ($expected['size'] ?? null)
                || ! hash_equals((string) ($expected['sha256'] ?? ''), (string) hash_file('sha256', $source))) {
                throw new \RuntimeException("An extracted release file failed verification: {$path}.");
            }
        }

        return $manifest;
    }

    /** @return array<string,string> */
    private function collect(string $root): array
    {
        $files = [];
        foreach (self::APP_DIRECTORIES as $directory) {
            $this->addDirectory($root.'/'.$directory, 'true_doctor_app/'.$directory, $files);
        }
        foreach (self::APP_FILES as $name) {
            $this->addFile($root.'/'.$name, 'true_doctor_app/'.$name, $files);
        }
        $this->addDirectory($root.'/public', 'web_root_files', $files);
        $this->addFile($root.'/deploy/docroot-index.php', 'web_root_files/index.php', $files, true);
        $this->addFile($root.'/deploy/docroot-htaccess', 'web_root_files/.htaccess', $files, true);

        ksort($files, SORT_STRING);

        return $files;
    }

    /** @param array<string,string> $files */
    private function addDirectory(string $source, string $destination, array &$files): void
    {
        if (! is_dir($source) || is_link($source)) {
            throw new \RuntimeException("Required package directory is missing: {$destination}.");
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            $path = $destination.'/'.substr($entry->getPathname(), strlen($source) + 1);
            if ($destination === 'web_root_files' && in_array($path, [
                'web_root_files/index.php', 'web_root_files/.htaccess',
                'web_root_files/googlefe4fcb0c9b8eddc9.html',
            ], true)) {
                continue;
            }
            // The public site's own font: a clinic's copy has no public site, and installed
            // copies (1.0.7 and earlier) refuse any package with a folder they do not know.
            if ($destination === 'web_root_files' && str_starts_with($path, 'web_root_files/fonts/')) {
                continue;
            }
            $this->addFile($entry->getPathname(), $path, $files);
        }
    }

    /** @param array<string,string> $files */
    private function addFile(string $source, string $destination, array &$files, bool $replace = false): void
    {
        if (! is_file($source) || is_link($source) || ! $this->safePath($destination) || (isset($files[$destination]) && ! $replace)) {
            throw new \RuntimeException("Unsafe or missing package file: {$destination}.");
        }
        $files[$destination] = $source;
    }

    private function safePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 500 || str_contains($path, '\\') || str_contains($path, "\0")
            || str_starts_with($path, '/') || str_contains($path, '//')
            || preg_match('~(^|/)(\.|\.\.)(/|$)~', $path)) {
            return false;
        }
        $parts = explode('/', strtolower($path));
        if (array_intersect($parts, self::DENIED_NAMES) !== []) {
            return false;
        }
        if (preg_match('/\.(?:pem|key|p12|pfx|sql|sqlite|sqlite3|log|bak|dump)$/i', $path)) {
            return false;
        }

        if ($path === 'PACKAGE.json') {
            return true;
        }
        if (str_starts_with($path, 'true_doctor_app/')) {
            $inside = substr($path, strlen('true_doctor_app/'));
            if (in_array($inside, self::APP_FILES, true)) {
                return true;
            }
            foreach (self::APP_DIRECTORIES as $directory) {
                if (str_starts_with($inside, $directory.'/')) {
                    return true;
                }
            }

            return false;
        }
        if (str_starts_with($path, 'web_root_files/')) {
            $inside = substr($path, strlen('web_root_files/'));
            if (in_array($inside, ['index.php', '.htaccess', 'install.php', 'sw.js', 'field-sw.js', 'robots.txt', 'favicon.png'], true)) {
                return true;
            }
            foreach (['build', 'images', 'vendor/fa'] as $directory) {
                if (str_starts_with($inside, $directory.'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string,string> */
    private function trustedKeys(): array
    {
        $keys = config('edition.public_keys', []);
        if (! is_array($keys)) {
            $keys = [];
        }
        $keyId = config('edition.signing_key_id');
        $secret = base64_decode((string) config('edition.signing_secret'), true);
        if (! is_string($keyId) || $keyId === '' || $secret === false
            || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('The release signing key is not configured.');
        }
        $keys[$keyId] = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));

        return $keys;
    }
}
