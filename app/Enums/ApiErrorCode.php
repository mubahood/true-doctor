<?php

namespace App\Enums;

/**
 * Stable machine-readable codes for the API error envelope (HMS_PLAN.md
 * §3.D). Clients branch on `code`, never on `message` (message is for
 * humans and may change wording without breaking integrations).
 */
enum ApiErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case TooManyRequests = 'too_many_requests';
    case ServerError = 'server_error';

    // States of the ACCOUNT rather than of the request, each with its own
    // screen in the app: signed out, set a password, renew.
    case AccountDisabled = 'account_disabled';
    case PasswordChangeRequired = 'password_change_required';
    case SubscriptionEnded = 'subscription_ended';
    case UpgradeRequired = 'upgrade_required';

    // Idempotency-Key misuse: the key was used for a different request, or
    // the same request is still being processed.
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case RequestInProgress = 'request_in_progress';

    // Precision added for the super app (docs/api/plane.md). Each keeps the
    // HTTP status the older, vaguer answer used, so an installed app that
    // branches on the status keeps working; a newer app can branch on the code.
    /** 409 - the record changed under the caller. */
    case Conflict = 'conflict';

    /** 403 - the plan or licence limit for this resource is reached. */
    case PlanLimit = 'plan_limit';

    /** 413 - the body or the upload is bigger than the server accepts. */
    case PayloadTooLarge = 'payload_too_large';

    /** 403 - this device was blocked; the app clears its local copy. */
    case DeviceRevoked = 'device_revoked';

    /** 402 - the standalone licence has lapsed. */
    case LicenceExpired = 'licence_expired';

    /** 503 - down for maintenance; retry later. */
    case Maintenance = 'maintenance';
}
