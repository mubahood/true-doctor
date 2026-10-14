<?php

namespace App\Support;

use App\Support\Practice\Practice;
use App\Support\Practice\Template;

/**
 * Curated starter data for the configuration catalogues. A fresh hospital can
 * import a sensible default set (and tweak prices) instead of typing every row
 * by hand.
 *
 * What is curated now depends on what kind of practice the hospital is
 * (docs/practice.md). The data itself lives in the specialty packs under
 * App\Support\Practice\Packs; this class answers for the hospital in context,
 * so a dental practice's price list offers crowns and dentures, its rooms are
 * chairs, its stock is dental materials, and its clinicians are offered
 * "Toothache" rather than "Fever" — on every page that imports from here, the
 * setup wizard and the full module pages alike.
 *
 * Prices are indicative, converted to the hospital's currency, and editable at
 * import time. A hospital that has not chosen a kind of practice gets a
 * general hospital's catalogue: every specialty, as it always did.
 */
class SampleCatalogue
{
    private static function template(): Template
    {
        return Practice::current()->template();
    }

    /**
     * Grouped by specialty, so a hospital sees the full range on offer.
     * `category` is the specialty, and `income_key` the income account its
     * services earn into; the full Services page's importer
     * (App\Livewire\Services\Index) reads both.
     *
     * @return list<array{name:string,price:float,tax_exempt:bool,category:string,income_key:?string}>
     */
    public static function services(): array
    {
        return self::template()->services();
    }

    /**
     * How a piece of work is usually written down, by kind of order.
     *
     * Only the kinds that take a free-text title are here. Lab and imaging
     * orders name catalogue rows instead — their tests and studies carry
     * results and reference ranges, so the picker has to be at placement —
     * and a phrase would compete with it rather than help.
     *
     * Pharmacy is NOT one of those: what was dispensed is an order ITEM, added
     * in the order's own dialog where it moves stock and bills in one
     * transaction (docs/orders.md). So placing one asks what it is, like any
     * other order, and these are the words for it.
     *
     * These are a FALLBACK. App\Livewire\Visits\Panels\Orders offers what this
     * hospital has actually written for that kind first, and tops up from here
     * — a hospital that says "Review BP" should be offered "Review BP", not a
     * phrase nobody there uses.
     *
     * @return array<string, list<string>> OrderType value => phrasings
     */
    public static function orderTitles(): array
    {
        return self::template()->orderTitles();
    }

    /**
     * Why people actually come in, in the words a front desk uses — the
     * practice's own reasons first.
     *
     * Not keyed by department: a reason is written before anyone knows where
     * the patient is going, and half of these fit any department. What the
     * hospital itself writes is blended in front of this (see the form).
     *
     * @return list<string>
     */
    public static function visitReasons(): array
    {
        return self::template()->visitReasons();
    }

    /**
     * The words a clinician reaches for, by field.
     *
     * A typing aid, not a menu to pick from: each one lands in the box as
     * editable text, and what this hospital actually writes is offered ahead
     * of these (see App\Livewire\Visits\Panels\Clinical).
     *
     * @return array{complaints:list<string>,diagnosis:list<string>,doctor_remarks:list<string>}
     */
    public static function clinicalPhrases(): array
    {
        return self::template()->clinicalPhrases();
    }

    /** @return list<array{name:string,specimen:string,unit:string,reference_range:string,price:float}> */
    public static function labTests(): array
    {
        return self::template()->labTests();
    }

    /** @return list<array{name:string,modality:string,body_part:string,price:float}> */
    public static function radiologyStudies(): array
    {
        return self::template()->radiologyStudies();
    }

    /** @return list<array{name:string,unit:string}> */
    public static function stockCategories(): array
    {
        return self::template()->stockCategories();
    }

    /**
     * A pharmacy worth opening with. Quantities are an opening count, and
     * `category` names a row from stockCategories() so the two import cleanly
     * together.
     *
     * @return list<array{name:string,category:string,unit:string,quantity:float,cost_price:float,sale_price:float,reorder_level:float}>
     */
    public static function stockItems(): array
    {
        return self::template()->stockItems();
    }

    /** @return list<array{name:string,code:string}> */
    public static function departments(): array
    {
        return self::template()->departments();
    }

    /**
     * `beds` and `daily_charge` are starter defaults for the onboarding wizard,
     * which creates a ward's beds in the same step. The full Wards page's
     * importer (App\Livewire\Wards\Index) only reads `name`.
     *
     * @return list<array{name:string,beds:int,daily_charge:float}>
     */
    public static function wards(): array
    {
        return self::template()->wards();
    }

    /**
     * Rooms in the practice's own terms — a dental practice's are chairs.
     * `type` matches App\Enums\RoomType.
     *
     * @return list<array{name:string,type:string,capacity:int}>
     */
    public static function rooms(): array
    {
        return self::template()->rooms();
    }
}
