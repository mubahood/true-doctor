<?php

namespace App\Support\Practice;

/**
 * One specialty's starter data — what a dental practice, an eye clinic or a
 * laboratory needs on day one that a general clinic does not.
 *
 * A kind of practice (Sector) is a handful of these, and a hospital can add
 * more of them to its own ("a clinic that also does dental"). Everything the
 * setup wizard suggests and every module page imports is these packs merged
 * in order — see Template — so a specialty is described once and shows up
 * everywhere: the price list, the rooms, the lab catalogue, the stock, the
 * words a clinician types, and the income account its work earns into.
 *
 * Prices are indicative UGX for a private facility in East Africa. Template
 * converts them to the hospital's own currency, and every one is editable
 * before anything is written.
 *
 * Each list is a plain tuple so a pack reads as a table. Override only what
 * the specialty has; everything else is empty.
 */
abstract class Pack
{
    /** Stable identifier, stored in the hospital's settings. */
    abstract public static function key(): string;

    /** Also the category its services are listed under on the price list. */
    abstract public static function label(): string;

    abstract public static function icon(): string;

    /** One line, shown where a hospital chooses what it offers. */
    abstract public static function summary(): string;

    /**
     * The modules this specialty cannot work without, switched on when it is
     * chosen. (Turning one off later is the hospital's call.)
     *
     * @return list<string>
     */
    public static function modules(): array
    {
        return [];
    }

    /**
     * The income account this specialty's services earn into, by system key.
     * Null: by the kind of order, like any other service (docs/finance.md).
     */
    public static function incomeKey(): ?string
    {
        return null;
    }

    /**
     * Accounts this specialty adds to the chart: [code, name, type, subtype, system_key|null].
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:?string}>
     */
    public static function accounts(): array
    {
        return [];
    }

    /** @return list<array{0:string,1:string}> [name, code] */
    public static function departments(): array
    {
        return [];
    }

    /** @return list<array{0:string,1:int|float}> [name, price] */
    public static function services(): array
    {
        return [];
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:int|float}> [name, specimen, unit, reference range, price] */
    public static function labTests(): array
    {
        return [];
    }

    /** @return list<array{0:string,1:string,2:string,3:int|float}> [name, modality, body part, price] */
    public static function radiologyStudies(): array
    {
        return [];
    }

    /** @return list<array{0:string,1:string}> [name, unit] */
    public static function stockCategories(): array
    {
        return [];
    }

    /** @return list<array{0:string,1:string,2:string,3:int|float,4:int|float,5:int|float,6:int|float}> [name, category, unit, opening qty, cost, sale, reorder level] */
    public static function stockItems(): array
    {
        return [];
    }

    /** @return list<array{0:string,1:string,2:int}> [name, App\Enums\RoomType value, capacity] */
    public static function rooms(): array
    {
        return [];
    }

    /** @return list<array{0:string,1:int,2:int|float}> [name, beds, nightly charge] */
    public static function wards(): array
    {
        return [];
    }

    /** @return list<string> */
    public static function visitReasons(): array
    {
        return [];
    }

    /** @return list<string> */
    public static function complaints(): array
    {
        return [];
    }

    /** @return list<string> */
    public static function diagnoses(): array
    {
        return [];
    }

    /** @return list<string> */
    public static function remarks(): array
    {
        return [];
    }

    /** @return array<string, list<string>> OrderType value => phrasings */
    public static function orderTitles(): array
    {
        return [];
    }
}
