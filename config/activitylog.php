<?php

/*
 * spatie/laravel-activitylog, set up as the hospital's write audit
 * (roadmap 07 §3, docs/audit.md).
 *
 * The one change from the package defaults is the model: AuditEntry carries
 * the tenant scope, so a hospital only ever reads its own trail, and refuses
 * to be edited or deleted through Eloquent, so the trail is append-only.
 * Nothing schedules `activitylog:clean`: an audit trail that deletes itself
 * after a year is not one a regulator would accept.
 */
return [

    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    'delete_records_older_than_days' => 3650,

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    'subject_returns_soft_deleted_models' => true,

    'activity_model' => \App\Models\AuditEntry::class,

    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
