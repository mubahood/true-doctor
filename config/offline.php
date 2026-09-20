<?php

/*
|--------------------------------------------------------------------------
| Offline-first
|--------------------------------------------------------------------------
| Knobs for Field Mode and the sync protocol
| (docs/OFFLINE_FIRST_IMPLEMENTATION_PLAN.md).
|
| The windows here decide how much patient data a device holds, which is the
| single most important security control in the whole feature: the smallest
| defensible copy is the one that is not there. A rural clinic with daily
| outages needs a wider window than an urban hospital with occasional flaps,
| so they are configurable — but the defaults assume the latter, because that
| is the safer thing to be wrong about.
*/

return [

    /*
    | Turning this off hides the entry point. It does NOT touch any device's
    | local database or its outbox, and a device with pending work can still
    | sync it — the rollback path must never destroy unsent work (plan §21).
    */
    'enabled' => env('OFFLINE_MODE_ENABLED', true),

    /*
    | The wire format. A device speaking a different one is refused before any
    | work happens, with a message telling the user to reload.
    */
    'protocol_version' => 1,

    /*
    | How much of the hospital a device keeps (plan §15).
    */
    'window' => [
        'patient_days' => env('OFFLINE_PATIENT_DAYS', 30),
        'visit_days' => env('OFFLINE_VISIT_DAYS', 7),
        'page_size' => env('OFFLINE_PAGE_SIZE', 200),
    ],

    /*
    | Batch and retention limits.
    */
    'push' => [
        'max_batch' => env('OFFLINE_MAX_BATCH', 200),
        // How long a result stays replayable. The client's backoff caps well
        // inside this; past it, a replay is refused rather than applied blind.
        'retention_days' => env('OFFLINE_OPERATION_RETENTION_DAYS', 90),
        // A claim older than this was abandoned by a process that died.
        'stale_claim_minutes' => env('OFFLINE_STALE_CLAIM_MINUTES', 10),
    ],

    /*
    | Load-test sizes for OfflineDemoSeeder. Here rather than as command-line
    | options because `db:seed` defines no options of its own, and rather than
    | bare env() calls because those read null once the config is cached.
    */
    'seed' => [
        'patients' => env('OFFLINE_SEED_PATIENTS', 1200),
        'inpatients' => env('OFFLINE_SEED_INPATIENTS', 40),
    ],

];
