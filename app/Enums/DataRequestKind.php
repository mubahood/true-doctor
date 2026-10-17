<?php

namespace App\Enums;

/** What a patient asked of the clinic under the Data Protection and Privacy Act 2019. */
enum DataRequestKind: string
{
    case Access = 'access';
    case Correction = 'correction';
    case Objection = 'objection';
    case Deletion = 'deletion';

    public function label(): string
    {
        return match ($this) {
            self::Access => 'See their data',
            self::Correction => 'Correct their data',
            self::Objection => 'Object to a use',
            self::Deletion => 'Delete their data',
        };
    }

    /** What answering it usually takes, shown beside the request. */
    public function hint(): string
    {
        return match ($this) {
            self::Access => 'Give them a copy: open their data as a PDF from this request.',
            self::Correction => 'Correct the record through the normal edit; the change is logged.',
            self::Objection => 'Usually messages: switch off the consent on their record.',
            self::Deletion => 'Medical records are kept for the retention period. Explain this, and record what was removed, if anything.',
        };
    }
}
