<?php

namespace App\Enums;

/**
 * The five kinds of account, and which side each one grows on.
 *
 * Assets and expenses grow with a debit; liabilities, equity and income grow
 * with a credit. That one fact is what lets a balance be read the right way
 * round on every statement, so it lives here and nowhere else.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expense',
        };
    }

    /** The heading a group of these sits under. */
    public function plural(): string
    {
        return match ($this) {
            self::Asset => 'Assets',
            self::Liability => 'Liabilities',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expenses',
        };
    }

    public function debitNormal(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }

    /**
     * A balance as this kind of account reads it: debits less credits for an
     * asset or an expense, credits less debits for the rest.
     */
    public function balance(string $debit, string $credit): string
    {
        return $this->debitNormal() ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
    }

    /** Income and expenses start again each year; the rest carry forward. */
    public function isTemporary(): bool
    {
        return $this === self::Income || $this === self::Expense;
    }

    /** The first digit of a code in the default chart. */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Asset => '1',
            self::Liability => '2',
            self::Equity => '3',
            self::Income => '4',
            self::Expense => '6',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Asset => 'info',
            self::Liability => 'warn',
            self::Equity => 'neutral',
            self::Income => 'success',
            self::Expense => 'danger',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $t) => $c + [$t->value => $t->label()], []);
    }
}
