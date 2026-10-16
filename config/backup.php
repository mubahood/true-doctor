<?php

/*
|--------------------------------------------------------------------------
| Backups of the online service
|--------------------------------------------------------------------------
|
| Every night the whole database, the settings and the uploaded documents
| are zipped, encrypted with BACKUP_ENCRYPTION_KEY and sent to S3-compatible
| storage off this server (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2).
| 30 daily and 12 monthly copies are kept. Once a month the newest copy is
| downloaded, decrypted and restored into a scratch database to prove it
| works (the restore drill). A standalone copy keeps its own nightly backup
| on its own server (standalone:backup).
|
| Keep BACKUP_ENCRYPTION_KEY somewhere other than this server: without it the
| backups cannot be opened, by anybody. `php artisan td:backup --new-key`
| prints a fresh one.
*/

return [

    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    's3' => [
        'endpoint' => env('BACKUP_S3_ENDPOINT'),      // e.g. https://s3.eu-central-003.backblazeb2.com
        'region' => env('BACKUP_S3_REGION', 'us-east-1'),
        'bucket' => env('BACKUP_S3_BUCKET'),
        'key' => env('BACKUP_S3_KEY'),
        'secret' => env('BACKUP_S3_SECRET'),
        'prefix' => env('BACKUP_S3_PREFIX', 'true-doctor'),
    ],

    'keep_daily' => 30,
    'keep_monthly' => 12,

    /*
    | The scratch database the restore drill fills and empties. Never the live
    | database: the drill refuses one with the same name and host.
    */
    'drill' => [
        'host' => env('BACKUP_DRILL_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('BACKUP_DRILL_DB_PORT', env('DB_PORT', '3306')),
        'socket' => env('BACKUP_DRILL_DB_SOCKET', env('DB_SOCKET', '')),
        'database' => env('BACKUP_DRILL_DB_DATABASE'),
        'username' => env('BACKUP_DRILL_DB_USERNAME'),
        'password' => env('BACKUP_DRILL_DB_PASSWORD'),
    ],
];
