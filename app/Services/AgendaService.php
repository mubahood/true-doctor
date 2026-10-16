<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\LabOrderStatus;
use App\Enums\VisitStatus;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rules\VisitsStuckOpen;
use App\Support\Practice\Practice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The person's next few days as one time-ordered list: appointments, doses
 * due, lab results ready, stock about to expire, invoices past their time,
 * visits left open. Each source is gated by the permission that opens it
 * (and the practice's modules), so a clerk never sees doses and a nurse
 * never sees invoices. Every item says where to go and what the tap does.
 *
 * Cached 45 s per hospital, user and window; every source is capped.
 */
class AgendaService
{
    public const TTL = 45;

    public const MAX_DAYS = 14;

    /** Days after issue at which an unpaid invoice counts as due (the footer's "payable within 30 days"). */
    public const INVOICE_TERMS_DAYS = 30;

    private const CAP = 25;

    private const PRIORITY = ['urgent' => 3, 'high' => 2, 'normal' => 1];

    public function __construct(private readonly WardRoundService $ward) {}

    /** @return list<array{kind:string,due_at:string,priority:string,subject:string,action:array{route:string,uuid:?string,verb:string}}> */
    public function for(User $user, int $days): array
    {
        $hospital = $user->hospital;
        if ($hospital === null) {
            return [];
        }
        $days = max(1, min($days, self::MAX_DAYS));

        return Cache::remember("agenda:v1:{$hospital->id}:{$user->id}:{$days}", self::TTL, function () use ($user, $hospital, $days) {
            $c = new InsightContext($user, $hospital, Practice::of($hospital), now());
            $until = $c->now->copy()->addDays($days);

            $rows = [
                ...$this->appointments($c, $until),
                ...$this->doses($c, $hospital, $days),
                ...$this->labs($c),
                ...$this->stock($c, $until),
                ...$this->invoices($c, $until),
                ...$this->openVisits($c),
            ];

            usort($rows, fn ($a, $b) => [$a['_at'], self::PRIORITY[$b['priority']]] <=> [$b['_at'], self::PRIORITY[$a['priority']]]);

            return array_map(fn (array $r) => [
                'kind' => $r['kind'],
                'due_at' => $r['_at']->toIso8601String(),
                'priority' => $r['priority'],
                'subject' => $r['subject'],
                'action' => $r['action'],
            ], $rows);
        });
    }

    /** @return list<array<string,mixed>> */
    private function appointments(InsightContext $c, Carbon $until): array
    {
        if (! $c->can('appointments.view')) {
            return [];
        }

        return Appointment::query()->with('patient:id,first_name,last_name')
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value, AppointmentStatus::CheckedIn->value])
            ->where('scheduled_at', '>=', $c->now->copy()->subHours(2))->where('scheduled_at', '<=', $until)
            ->when(! $c->can('appointments.manage'), fn ($q) => $q->where('doctor_user_id', $c->user->id))
            ->orderBy('scheduled_at')->limit(self::CAP)->get()
            ->map(fn (Appointment $a) => $this->row(
                'appointment', Carbon::instance($a->scheduled_at),
                $a->status === AppointmentStatus::CheckedIn || $a->scheduled_at->lte($c->now->copy()->addHour()) ? 'high' : 'normal',
                $a->scheduled_at->format('H:i').' '.($a->patient?->full_name ?: 'Appointment').($a->reason ? " - {$a->reason}" : ''),
                'admin.appointments.show', $a->uuid, $a->status === AppointmentStatus::CheckedIn ? 'Start' : 'Open',
            ))->all();
    }

    /** @return list<array<string,mixed>> */
    private function doses(InsightContext $c, \App\Models\Hospital $hospital, int $days): array
    {
        if (! ($c->can('prescriptions.administer') && $c->can('ipd.view'))) {
            return [];
        }

        return $this->ward->dosesDue($hospital, null, $days * 1440)->take(self::CAP * 2)
            ->map(fn (array $d) => $this->row(
                'dose', Carbon::parse($d['due_at']), $d['overdue_minutes'] > 0 ? 'urgent' : 'high',
                ($d['patient']['name'] ?? 'Patient').' - '.trim($d['drug'].' '.$d['dose']).($d['bed']['name'] ? " ({$d['bed']['name']})" : ''),
                'admin.admissions.show', $d['admission_uuid'], 'Give',
            ))->all();
    }

    /** @return list<array<string,mixed>> */
    private function labs(InsightContext $c): array
    {
        if (! $c->can('lab.view')) {
            return [];
        }

        return LabOrder::query()->with('patient:id,first_name,last_name')
            ->where('ordered_by', $c->user->id)->where('status', LabOrderStatus::Completed->value)
            ->where('completed_at', '>=', $c->now->copy()->subDay())
            ->orderByDesc('completed_at')->limit(self::CAP)->get()
            ->map(fn (LabOrder $o) => $this->row(
                'lab-result', Carbon::instance($o->completed_at), 'normal',
                'Result ready: '.($o->patient?->full_name ?: 'patient'), 'admin.lab-orders.show', $o->uuid, 'Review',
            ))->all();
    }

    /** @return list<array<string,mixed>> */
    private function stock(InsightContext $c, Carbon $until): array
    {
        if (! $c->can('pharmacy.view')) {
            return [];
        }

        return StockItem::query()->where('is_active', true)->where('current_quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $c->now->toDateString())->whereDate('expiry_date', '<=', $until->toDateString())
            ->orderBy('expiry_date')->limit(self::CAP)->get()
            ->map(fn (StockItem $s) => $this->row(
                'stock-expiring', $s->expiry_date->copy()->startOfDay(),
                $s->expiry_date->lte($c->now->copy()->addDays(3)) ? 'high' : 'normal',
                "{$s->name} expires ".$s->expiry_date->format('d M'), 'admin.stock.show', $s->uuid, 'Review',
            ))->all();
    }

    /** @return list<array<string,mixed>> */
    private function invoices(InsightContext $c, Carbon $until): array
    {
        if (! $c->can('billing.view')) {
            return [];
        }

        return Invoice::query()->outstanding()->with('patient:id,first_name,last_name')
            ->where('created_at', '<=', $until->copy()->subDays(self::INVOICE_TERMS_DAYS))
            ->orderBy('created_at')->limit(self::CAP)->get()
            ->map(function (Invoice $i) use ($c) {
                $due = Carbon::instance($i->created_at)->addDays(self::INVOICE_TERMS_DAYS);

                return $this->row(
                    'invoice-unpaid', $due, $due->lt($c->now) ? 'high' : 'normal',
                    "{$i->invoice_no} - ".($i->patient?->full_name ?: 'patient').' owes '.\App\Support\HospitalSettings::money($i->balance),
                    'admin.invoices.show', $i->uuid, 'Collect',
                );
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function openVisits(InsightContext $c): array
    {
        if (! $c->can('visits.view')) {
            return [];
        }

        return Visit::query()->with('patient:id,first_name,last_name')
            ->whereIn('status', [VisitStatus::Pending->value, VisitStatus::Ongoing->value])
            ->where('created_at', '<=', $c->now->copy()->subDays(VisitsStuckOpen::DAYS))
            ->when($c->user->role === 'doctor', fn ($q) => $q->where('doctor_user_id', $c->user->id))
            ->orderBy('created_at')->limit(self::CAP)->get()
            ->map(fn (Visit $v) => $this->row(
                'visit-open', Carbon::instance($v->created_at)->addDays(VisitsStuckOpen::DAYS), 'normal',
                "{$v->visit_no} - ".($v->patient?->full_name ?: 'patient').' is still open', 'admin.visits.show', $v->uuid, 'Complete',
            ))->all();
    }

    /** @return array{kind:string,_at:Carbon,priority:string,subject:string,action:array{route:string,uuid:?string,verb:string}} */
    private function row(string $kind, Carbon $at, string $priority, string $subject, string $route, ?string $uuid, string $verb): array
    {
        return ['kind' => $kind, '_at' => $at, 'priority' => $priority, 'subject' => $subject, 'action' => ['route' => $route, 'uuid' => $uuid, 'verb' => $verb]];
    }
}
