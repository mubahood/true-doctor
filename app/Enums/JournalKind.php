<?php

namespace App\Enums;

/**
 * Where a journal entry came from.
 *
 * `system` entries are posted by the hospital's own work — an invoice, a
 * payment, a delivery of stock — and are never written by hand. The rest are
 * somebody at a desk: money in, money out, money moved between books, an
 * accountant's journal, the balances a hospital started with, and the entry
 * that undoes another.
 */
enum JournalKind: string
{
    case System = 'system';
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';
    case Journal = 'journal';
    case Opening = 'opening';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::System => 'From the system',
            self::Income => 'Money in',
            self::Expense => 'Money out',
            self::Transfer => 'Transfer',
            self::Journal => 'Journal',
            self::Opening => 'Opening balance',
            self::Reversal => 'Reversal',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::System => 'neutral',
            self::Income => 'success',
            self::Expense => 'danger',
            self::Transfer => 'info',
            self::Journal => 'warn',
            self::Opening => 'info',
            self::Reversal => 'warn',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::System => 'fa-gears',
            self::Income => 'fa-arrow-down',
            self::Expense => 'fa-arrow-up',
            self::Transfer => 'fa-right-left',
            self::Journal => 'fa-book',
            self::Opening => 'fa-flag',
            self::Reversal => 'fa-rotate-left',
        };
    }

    /** Written by a person, so a person may reverse it. A system entry is reversed by undoing its cause. */
    public function isManual(): bool
    {
        return $this !== self::System && $this !== self::Reversal;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $k) => $c + [$k->value => $k->label()], []);
    }
}
