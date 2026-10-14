<?php

return [
    'name' => env('APP_EDITION', 'saas'),
    'licence_path' => env('APP_LICENCE_PATH', storage_path('app/private/licence.json')),
    'installed_path' => storage_path('app/private/installed.json'),
    'update_server' => env('APP_UPDATE_SERVER', 'https://true-doctor.online'),
    // Only public keys belong in a customer package. New keys are added before
    // signing with them; old keys stay for licences already in circulation.
    // The release builder adds only public keys to this package. The key
    // already installed on a server is used to verify its next update.
    'public_keys' => (static function (): array {
        $file = dirname(__DIR__).'/RELEASE_KEYS.json';
        $keys = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($keys) ? $keys : [];
    })(),
    'signing_key_id' => env('RELEASE_SIGNING_KEY_ID'),
    'signing_secret' => env('RELEASE_SIGNING_SECRET'),
    'release_source' => env('RELEASE_SOURCE_PATH') ?: base_path(),
    // Where Composer is, for a build that cannot reuse the deployed vendor/.
    // Unset: ~/bin/composer, then whatever `composer` is on the PATH.
    'composer_binary' => env('RELEASE_COMPOSER'),
    // The MySQL account a release's clean-install proof runs against. With a
    // database named, that one database is emptied and used (cPanel accounts
    // cannot CREATE DATABASE over SQL); without one, a throwaway database is
    // created and dropped. Never the application's own database.
    'test_database' => [
        'host' => env('RELEASE_TEST_DB_HOST'),
        'port' => env('RELEASE_TEST_DB_PORT', '3306'),
        'database' => env('RELEASE_TEST_DB_DATABASE'),
        'user' => env('RELEASE_TEST_DB_USER'),
        'password' => env('RELEASE_TEST_DB_PASSWORD'),
    ],
];
