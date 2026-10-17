<?php

namespace App\Enums;

/** A data request is open until it is answered (done) or refused with a reason. Never reopened. */
enum DataRequestStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Done => 'Answered',
            self::Refused => 'Refused',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'warn',
            self::Done => 'success',
            self::Refused => 'neutral',
        };
    }
}
