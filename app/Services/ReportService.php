<?php

namespace App\Services;

use App\Enums\BedStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Enums\PatientSex;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\StockItem;
use App\Models\Visit;
use Illuminate\Support\Carbon;

/**
 * Read-only analytics for the reports dashboard. Everything is tenant-scoped by
 * the global scope; money is summed in bcmath (no float drift). Kept as plain
 * aggregate queries — no caching yet, fine at single-hospital report volumes.
 */
class ReportService
{
    /**
     * @return array{total:string,by_method:array<string,string>,by_day:array<string,string>,count:int}
     */
    public function revenue(Carbon $from, Carbon $to): array
    {
        $payments = Payment::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['method', 'amount', 'created_at']);

        $total = '0.00';
        $byMethod = [];
        $byDay = [];
        foreach ($payments as $p) {
            $total = bcadd($total, (string) $p->amount, 2);
            $m = $p->method->value;
            $byMethod[$m] = bcadd($byMethod[$m] ?? '0.00', (string) $p->amount, 2);
            $d = $p->created_at->toDateString();
            $byDay[$d] = bcadd($byDay[$d] ?? '0.00', (string) $p->amount, 2);
        }
        ksort($byDay);

        return ['total' => $total, 'by_method' => $byMethod, 'by_day' => $byDay, 'count' => $payments->count()];
    }

    /** @return list<array{doctor:string,visits:int,appointments:int}> */
    public function doctorProductivity(Carbon $from, Carbon $to): array
    {
        $consults = Visit::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNotNull('doctor_user_id')->with('doctor')->get()->groupBy('doctor_user_id');
        $appts = Appointment::whereBetween('scheduled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with('doctor')->get()->groupBy('doctor_user_id');

        $rows = [];
        foreach ($consults as $id => $group) {
            $rows[$id] = ['doctor' => $group->first()->doctor->name, 'visits' => $group->count(), 'appointments' => 0];
        }
        foreach ($appts as $id => $group) {
            $rows[$id] ??= ['doctor' => $group->first()->doctor->name, 'visits' => 0, 'appointments' => 0];
            $rows[$id]['appointments'] = $group->count();
        }
        usort($rows, fn ($a, $b) => ($b['visits'] + $b['appointments']) <=> ($a['visits'] + $a['appointments']));

        return $rows;
    }

    /** @return array{total:int,by_sex:array<string,int>,by_status:array<string,int>,by_age:array<string,int>} */
    public function demographics(): array
    {
        $patients = Patient::get(['sex', 'status', 'dob']);
        $bySex = ['male' => 0, 'female' => 0, 'other' => 0, 'unknown' => 0];
        $byStatus = [];
        $byAge = ['0-17' => 0, '18-39' => 0, '40-64' => 0, '65+' => 0, 'unknown' => 0];

        foreach ($patients as $p) {
            $sex = $p->sex instanceof PatientSex ? $p->sex->value : 'unknown';
            $bySex[$sex]++;
            $st = $p->status->value;
            $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
            $age = $p->dob?->age;
            $band = $age === null ? 'unknown' : ($age < 18 ? '0-17' : ($age < 40 ? '18-39' : ($age < 65 ? '40-64' : '65+')));
            $byAge[$band]++;
        }

        return ['total' => $patients->count(), 'by_sex' => array_filter($bySex), 'by_status' => $byStatus, 'by_age' => $byAge];
    }

    /** @return list<array{name:string,count:int,revenue:string}> */
    public function serviceRevenue(Carbon $from, Carbon $to): array
    {
        $lines = OrderItem::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where('status', '!=', OrderItemStatus::Cancelled->value)
            ->get(['name', 'line_total'])->groupBy('name');

        $rows = [];
        foreach ($lines as $name => $group) {
            $revenue = '0.00';
            foreach ($group as $l) {
                $revenue = bcadd($revenue, (string) $l->line_total, 2);
            }
            $rows[] = ['name' => $name, 'count' => $group->count(), 'revenue' => $revenue];
        }
        usort($rows, fn ($a, $b) => bccomp($b['revenue'], $a['revenue'], 2));

        return $rows;
    }

    /** @return array{total_value:string,items:int,low_stock:int,expiring:int} */
    public function stockValuation(): array
    {
        $items = StockItem::where('is_active', true)->get(['current_quantity', 'current_stock_value', 'reorder_level', 'expiry_date']);
        $total = '0.00';
        $low = 0;
        $exp = 0;
        $horizon = Carbon::now()->addDays(90);
        foreach ($items as $i) {
            $total = bcadd($total, (string) $i->current_stock_value, 2);
            if (bccomp((string) $i->current_quantity, (string) $i->reorder_level, 2) <= 0) {
                $low++;
            }
            if ($i->expiry_date !== null && $i->expiry_date->lte($horizon)) {
                $exp++;
            }
        }

        return ['total_value' => $total, 'items' => $items->count(), 'low_stock' => $low, 'expiring' => $exp];
    }

    /** @return array{total:int,occupied:int,available:int,rate:int} */
    public function occupancy(): array
    {
        $beds = Bed::where('is_active', true)->get(['status']);
        $total = $beds->count();
        $occupied = $beds->where('status', BedStatus::Occupied)->count();

        return [
            'total' => $total,
            'occupied' => $occupied,
            'available' => $beds->where('status', BedStatus::Available)->count(),
            'rate' => $total > 0 ? (int) round($occupied / $total * 100) : 0,
        ];
    }

    /**
     * Inpatient nights and what they were worth.
     *
     * Reads the stay orders rather than recomputing rate × nights: since a
     * stay is billed a night at a time, at the rate in force on each night,
     * there is no single rate left to multiply by. What was charged is the
     * only honest answer, and it is on the order.
     *
     * @return array{nights:int,revenue:string,stays:int,discharged:int,by_ward:list<array{ward:string,nights:int,revenue:string}>}
     */
    public function inpatientNights(Carbon $from, Carbon $to): array
    {
        $items = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('type', OrderType::Admission->value))
            ->where('name', 'like', 'Bed charge%')
            ->whereNot('status', OrderItemStatus::Cancelled->value)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with('order.visit')
            ->get(['id', 'order_id', 'name', 'line_total', 'quantity']);

        $revenue = '0.00';
        $nights = 0;

        foreach ($items as $item) {
            $revenue = bcadd($revenue, (string) $item->line_total, 2);
            // One item is one night now. An older stay billed as a single
            // "3 night(s)" line still counts its nights, from its own name.
            $nights += (int) (preg_match('/(\d+)\s+night/i', (string) $item->name, $m) ? $m[1] : 1);
        }

        // Which wards those nights were spent in. Taken from the admission's
        // CURRENT bed, which is the best available answer — a patient moved
        // between wards mid-stay counts to where they ended up.
        $stays = Admission::query()
            ->with('bed.ward')
            ->where(fn ($q) => $q
                ->whereBetween('admitted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->orWhereBetween('discharged_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->orWhere(fn ($qq) => $qq->where('admitted_at', '<', $from)->whereNull('discharged_at')))
            ->get();

        $byWard = [];

        foreach ($stays as $stay) {
            $ward = $stay->bed?->ward->name ?? 'No ward';
            $byWard[$ward] ??= ['ward' => $ward, 'nights' => 0, 'revenue' => '0.00'];
            $byWard[$ward]['nights'] += (int) $stay->nights_billed;
            $byWard[$ward]['revenue'] = bcadd($byWard[$ward]['revenue'], (string) $stay->bed_charge_total, 2);
        }

        usort($byWard, fn ($a, $b) => bccomp($b['revenue'], $a['revenue'], 2));

        return [
            'nights' => $nights,
            'revenue' => $revenue,
            'stays' => $stays->count(),
            'discharged' => $stays->whereNotNull('discharged_at')->count(),
            'by_ward' => $byWard,
        ];
    }

    /**
     * What has been billed and not yet paid.
     *
     * The figure a hospital administrator asks for first and the dashboard
     * never showed: revenue says what came in, and says nothing about what is
     * owed. Ages it, because a balance thirty days old is a different problem
     * from one raised this morning.
     *
     * @return array{total:string,count:int,buckets:array<string,string>}
     */
    public function outstanding(): array
    {
        $invoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->get(['balance', 'created_at']);

        $total = '0.00';
        $count = 0;
        $buckets = ['0-7 days' => '0.00', '8-30 days' => '0.00', '31-90 days' => '0.00', 'Over 90 days' => '0.00'];
        $now = Carbon::now();

        foreach ($invoices as $invoice) {
            $balance = (string) $invoice->balance;

            // An issued invoice that has been paid down to nothing is not
            // money owed, and counting it would make the figure beside the
            // total disagree with the total.
            if (bccomp($balance, '0', 2) <= 0) {
                continue;
            }

            $total = bcadd($total, $balance, 2);
            $count++;
            $age = (int) $invoice->created_at->diffInDays($now);

            $key = match (true) {
                $age <= 7 => '0-7 days',
                $age <= 30 => '8-30 days',
                $age <= 90 => '31-90 days',
                default => 'Over 90 days',
            };

            $buckets[$key] = bcadd($buckets[$key], $balance, 2);
        }

        return ['total' => $total, 'count' => $count, 'buckets' => $buckets];
    }
}
