<?php

namespace App\Enums;

/**
 * The two documents that give money back on an invoice (docs/refunds.md).
 *
 * A credit note takes money off what the invoice asks for; a refund pays
 * money out to the patient. A refund only ever pays back what the patient
 * paid beyond what the invoice now asks for, so it always follows a credit
 * note: refunding without one would leave the patient owing it again.
 */
enum CreditKind: string
{
    case CreditNote = 'credit_note';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::CreditNote => 'Credit note',
            self::Refund => 'Refund',
        };
    }

    /** The document number's prefix: CN-2026-00001, RF-2026-00001. */
    public function prefix(): string
    {
        return $this === self::CreditNote ? 'CN' : 'RF';
    }

    public function icon(): string
    {
        return $this === self::CreditNote ? 'fa-file-circle-minus' : 'fa-money-bill-transfer';
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
