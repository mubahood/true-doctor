<?php

namespace App\Support;

use App\Enums\RadiologyOrderStatus;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The radiology room's reading of its own work — the lab bench's twin
 * (LabBench): what is outstanding, how old the oldest of it is, and which
 * orders a search or filter means. Shared by the web worklist and the app.
 */
final class RadiologyBench
{
    /** Waiting longer than this is the thing the room needs to see. */
    public const OLD_HOURS = 24;

    /** @return list<string> the statuses that still owe a report */
    public static function outstandingStatuses(): array
    {
        return [
            RadiologyOrderStatus::Ordered->value,
            RadiologyOrderStatus::Scheduled->value,
            RadiologyOrderStatus::Performed->value,
        ];
    }

    /** @return array{ordered:int,scheduled:int,performed:int,reported:int,oldest:int|null} */
    public static function tally(): array
    {
        $counts = RadiologyOrder::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $oldest = RadiologyOrder::query()
            ->whereIn('status', self::outstandingStatuses())
            ->min('created_at');

        return [
            'ordered' => (int) ($counts[RadiologyOrderStatus::Ordered->value] ?? 0),
            'scheduled' => (int) ($counts[RadiologyOrderStatus::Scheduled->value] ?? 0),
            'performed' => (int) ($counts[RadiologyOrderStatus::Performed->value] ?? 0),
            'reported' => (int) RadiologyOrder::whereDate('reported_at', now()->toDateString())->count(),
            'oldest' => $oldest === null ? null : (int) Carbon::parse($oldest)->diffInHours(now()),
        ];
    }

    public static function waitedHours(RadiologyOrder $order): int
    {
        return (int) $order->created_at->diffInHours(now());
    }

    public static function isOverdue(RadiologyOrder $order): bool
    {
        return ! $order->status->isTerminal() && self::waitedHours($order) >= self::OLD_HOURS;
    }

    /**
     * @param  Builder<RadiologyOrder>  $query
     * @return Builder<RadiologyOrder>
     */
    public static function filter(Builder $query, ?string $status, bool $outstanding, ?string $search): Builder
    {
        $search = trim((string) $search);

        return $query
            ->when(($status ?? '') !== '', fn (Builder $q) => $q->where('status', $status))
            ->when($outstanding, fn (Builder $q) => $q->whereIn('status', self::outstandingStatuses()))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('patient', fn ($p) => Patient::matchWords($p, $search)));
    }
}
