<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Every status and option list the app renders, with the web's label and badge
 * tone for each value - built from an EXPLICIT list of enums and cached.
 *
 * It used to `glob()` app/Enums and reflect on every class on every `meta`
 * call. Now the list is written down (a test fails if an enum with a `label()`
 * is missing from it) and the result is cached under a key made from the app
 * version and the newest modification time among those files, so a deploy that
 * changes a label changes the key by itself - no manual cache clear, no
 * dependence on the release version being bumped. File cache is enough.
 */
final class EnumCatalogue
{
    /** @var list<class-string<\UnitEnum>> */
    public const ENUMS = [
        \App\Enums\AccountType::class,
        \App\Enums\AdmissionStatus::class,
        \App\Enums\AppointmentSource::class,
        \App\Enums\AppointmentStatus::class,
        \App\Enums\BedStatus::class,
        \App\Enums\BillingCycle::class,
        \App\Enums\CardEntryType::class,
        \App\Enums\CardHolderRelationship::class,
        \App\Enums\CardHolderStatus::class,
        \App\Enums\CardStatus::class,
        \App\Enums\ClaimStatus::class,
        \App\Enums\CreditKind::class,
        \App\Enums\CreditStatus::class,
        \App\Enums\DentalFinding::class,
        \App\Enums\DentalTreatmentStatus::class,
        \App\Enums\DiscountType::class,
        \App\Enums\DoseRecordStatus::class,
        \App\Enums\DoseSlot::class,
        \App\Enums\FinancialYearStatus::class,
        \App\Enums\HospitalStatus::class,
        \App\Enums\InsuranceEntryType::class,
        \App\Enums\InvoiceStatus::class,
        \App\Enums\JournalKind::class,
        \App\Enums\LabOrderStatus::class,
        \App\Enums\MedicationAdminStatus::class,
        \App\Enums\MessageChannel::class,
        \App\Enums\MessagePurpose::class,
        \App\Enums\MessageStatus::class,
        \App\Enums\OrderItemStatus::class,
        \App\Enums\OrderStatus::class,
        \App\Enums\OrderType::class,
        \App\Enums\PatientSex::class,
        \App\Enums\PatientStatus::class,
        \App\Enums\PaymentMethod::class,
        \App\Enums\RadiologyOrderStatus::class,
        \App\Enums\ResultFlag::class,
        \App\Enums\RoomStatus::class,
        \App\Enums\RoomType::class,
        \App\Enums\StockMovementReason::class,
        \App\Enums\SubscriptionStatus::class,
        \App\Enums\VisitOutcome::class,
        \App\Enums\VisitStage::class,
        \App\Enums\VisitStatus::class,
        \App\Enums\Weekday::class,
    ];

    /** Badge classes -> the handful of tones a client needs to know. */
    private const TONES = [
        'badge-success' => 'success', 'badge-active' => 'success', 'badge-verified' => 'success',
        'badge-info' => 'info', 'badge-pending' => 'info', 'badge-ongoing' => 'info',
        'badge-warn' => 'warn', 'badge-high' => 'warn',
        'badge-danger' => 'danger', 'badge-closed' => 'danger',
        'badge-neutral' => 'neutral', 'badge-brown' => 'neutral',
    ];

    /** @return array<string, list<array{value:string, label:string, tone:?string, icon:?string}>> */
    public static function all(): array
    {
        return Cache::remember(self::cacheKey(), now()->addDay(), fn () => self::build());
    }

    /** A badge class (`badge-info`) or a bare tone (`active`) as a client tone. */
    public static function tone(string $badge): string
    {
        $class = str_starts_with($badge, 'badge-') ? $badge : 'badge-'.$badge;

        return self::TONES[$class] ?? 'neutral';
    }

    public static function cacheKey(): string
    {
        $newest = 0;
        foreach (self::ENUMS as $class) {
            $file = app_path('Enums/'.class_basename($class).'.php');
            $newest = max($newest, is_file($file) ? (int) filemtime($file) : 0);
        }

        return 'meta:enums:'.Edition::version().':'.$newest.':'.count(self::ENUMS).':'.app()->getLocale();
    }

    /** @return array<string, list<array{value:string, label:string, tone:?string, icon:?string}>> */
    private static function build(): array
    {
        $out = [];

        foreach (self::ENUMS as $class) {
            if (! enum_exists($class) || ! method_exists($class, 'label')) {
                continue;
            }

            $out[Str::snake(class_basename($class))] = array_map(fn (\UnitEnum $case) => [
                'value' => property_exists($case, 'value') ? (string) $case->value : $case->name,
                'label' => $case->label(),
                'tone' => method_exists($case, 'badge') ? self::tone((string) $case->badge()) : null,
                'icon' => method_exists($case, 'icon') ? $case->icon() : null,
            ], $class::cases());
        }

        ksort($out);

        return $out;
    }
}
