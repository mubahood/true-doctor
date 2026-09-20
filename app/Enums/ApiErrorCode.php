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
}
