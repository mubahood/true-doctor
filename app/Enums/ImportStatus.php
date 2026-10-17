<?php

namespace App\Enums;

/**
 * Where one import stands. Work that runs in steps (checking, importing,
 * undoing) is picked up again where it stopped if the page is closed.
 */
enum ImportStatus: string
{
    case Mapping = 'mapping';
    case Checking = 'checking';
    case Checked = 'checked';
    case Importing = 'importing';
    case Done = 'done';
    case Undoing = 'undoing';
    case Undone = 'undone';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Mapping => 'Matching columns',
            self::Checking => 'Checking',
            self::Checked => 'Checked, not saved',
            self::Importing => 'Importing',
            self::Done => 'Imported',
            self::Undoing => 'Undoing',
            self::Undone => 'Undone',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Mapping, self::Checked => 'info',
            self::Checking, self::Importing, self::Undoing => 'warn',
            self::Done => 'success',
            self::Undone, self::Cancelled => 'neutral',
        };
    }

    /** Steps that run a chunk at a time until they finish. */
    public function isWorking(): bool
    {
        return in_array($this, [self::Checking, self::Importing, self::Undoing], true);
    }

    /** Nothing saved yet: the batch can still be changed or thrown away. */
    public function isDraft(): bool
    {
        return in_array($this, [self::Mapping, self::Checking, self::Checked], true);
    }
}
