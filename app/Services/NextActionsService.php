<?php

namespace App\Services;

use App\Enums\LabOrderStatus;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\Admission;
use App\Models\LabOrder;
use App\Models\User;
use App\Models\Visit;
use App\Support\Insights\InsightContext;
use App\Support\Insights\InsightEngine;
use App\Support\LabBench;
use App\Support\Practice\Practice;
use Illuminate\Support\Facades\Cache;

/**
 * "What should I do next?" for one person: a short ranked list, role-aware
 * because it is permission-aware (a doctor's, a nurse's, a cashier's list come
 * from what each may act on, not from a role name).
 *
 * Two sources, one shape. The Insight rules (stock, billing, queue, setup,
 * licence...) are asked through InsightEngine and mapped; then the few
 * things that are about THIS person's work and not the hospital's - my next
 * patient, doses due on the ward, prescriptions waiting at the counter - are
 * added from on-demand, indexed queries. Cached 45 s per hospital and user.
 */
class NextActionsService
{
    public const TTL = 45;

    public const MAX = 8;

    private const URGENCY = ['urgent' => 3, 'high' => 2, 'normal' => 1];

    /** Insight keys replaced by a sharper item of ours when we have one. */
    private const SUPERSEDED = ['lab-waiting' => 'lab-queue'];

    public function __construct(
        private readonly InsightEngine $insights,
        private readonly WardRoundService $ward,
        private readonly DispenseQueueService $dispense,
    ) {}

    /** @return list<array{kind:string,title:string,sub:string,route:string,uuid:?string,urgency:string,count:?int}> */
    public function for(User $user): array
    {
        $hospital = $user->hospital;
        if ($hospital === null) {
            return [];
        }

        return Cache::remember("next-actions:v1:{$hospital->id}:{$user->id}", self::TTL, function () use ($user, $hospital) {
            $ctx = new InsightContext($user, $hospital, Practice::of($hospital), now());

            $own = array_values(array_filter([
                ...$this->doctor($ctx),
                ...$this->nurse($ctx, $hospital),
                ...$this->pharmacist($ctx),
                ...$this->frontDesk($ctx),
                ...$this->lab($ctx),
            ]));
            $kinds = array_column($own, 'kind');
            $drop = array_keys(array_filter(self::SUPERSEDED, fn ($by) => in_array($by, $kinds, true)));

            $items = $own;
            foreach ($this->insights->for($user, InsightEngine::MAX_LIMIT) as $i => $insight) {
                if ($insight['key'] === 'all-clear' || in_array($insight['key'], $drop, true)) {
                    continue;
                }
                $items[] = $this->item(
                    $insight['key'], $insight['title'], $insight['detail'], $insight['route'], null,
                    match ($insight['tone']) {
                        'danger' => 'urgent', 'warn' => 'high', default => 'normal'
                    },
                    $insight['count'], max(20, 90 - $i * 3),
                );
            }

            usort($items, fn ($a, $b) => [self::URGENCY[$b['urgency']], $b['_p']] <=> [self::URGENCY[$a['urgency']], $a['_p']]);

            return array_map(function (array $i) {
                unset($i['_p']);

                return $i;
            }, array_slice($items, 0, self::MAX));
        });
    }

    /** @return list<array<string,mixed>> */
    private function doctor(InsightContext $c): array
    {
        $out = [];

        if ($c->can('visits.diagnose')) {
            $next = Visit::query()->with('patient:id,first_name,last_name')
                ->where('doctor_user_id', $c->user->id)
                ->whereIn('status', [VisitStatus::Pending->value, VisitStatus::Ongoing->value])
                ->where('stage', VisitStage::Ongoing->value)
                ->whereNotNull('vitals_recorded_at')
                ->orderBy('vitals_recorded_at')->first();

            if ($next !== null) {
                $mins = max(0, (int) $next->vitals_recorded_at->diffInMinutes($c->now));
                $out[] = $this->item('next-patient', 'Next patient: '.($next->patient?->full_name ?: 'patient'), "Vitals done {$mins} min ago.", 'admin.visits.show', $next->uuid, 'high', null, 92);
            }
        }

        if ($c->can('lab.view')) {
            $ready = LabOrder::query()->where('ordered_by', $c->user->id)
                ->where('status', LabOrderStatus::Completed->value)
                ->where('completed_at', '>=', $c->now->copy()->subDay());
            $n = (clone $ready)->count();
            if ($n > 0) {
                $one = $n === 1 ? (clone $ready)->value('uuid') : null;
                $out[] = $this->item('results-ready', $n === 1 ? '1 lab result is ready' : "{$n} lab results are ready", 'Ordered by you, completed in the last day.', $one ? 'admin.lab-orders.show' : 'admin.lab-orders.index', $one, 'normal', $n, 65);
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function nurse(InsightContext $c, \App\Models\Hospital $hospital): array
    {
        $out = [];

        if ($c->can('prescriptions.administer') && $c->can('ipd.view')) {
            $due = $this->ward->dosesDue($hospital, null, 30);
            $overdue = $due->where('overdue_minutes', '>', 0);
            if ($overdue->isNotEmpty()) {
                $n = $overdue->count();
                $out[] = $this->item('doses-overdue', $n === 1 ? '1 dose is overdue' : "{$n} doses are overdue", 'Oldest '.$overdue->max('overdue_minutes').' min late. Give or record why not.', 'admin.admissions.board', $n === 1 ? $overdue->first()['admission_uuid'] : null, 'urgent', $n, 95);
            }
            $soon = $due->count() - $overdue->count();
            if ($soon > 0) {
                $out[] = $this->item('doses-due', $soon === 1 ? '1 dose due soon' : "{$soon} doses due soon", 'Within the next 30 minutes.', 'admin.admissions.board', null, 'high', $soon, 88);
            }
        }

        if ($c->can('visits.vitals') && $c->can('ipd.view')) {
            $n = Admission::query()->where('status', 'admitted')
                ->whereDoesntHave('vitalRounds', fn ($q) => $q->where('created_at', '>=', $c->now->copy()->subHours(6)))->count();
            if ($n > 0) {
                $out[] = $this->item('vitals-due', $n === 1 ? '1 patient needs observations' : "{$n} patients need observations", 'No vitals recorded in the last 6 hours.', 'admin.admissions.board', null, 'normal', $n, 70);
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function pharmacist(InsightContext $c): array
    {
        if (! $c->can('pharmacy.dispense')) {
            return [];
        }

        $queue = $this->dispense->pending();
        if ($queue->isEmpty()) {
            return [];
        }

        $oldest = $queue->first();
        $n = $queue->count();

        return [$this->item(
            'pending-prescriptions', $n === 1 ? '1 prescription to dispense' : "{$n} prescriptions to dispense",
            "Oldest has waited {$oldest['waited_minutes']} min.", 'admin.visits.show', $oldest['visit_uuid'],
            $oldest['waited_minutes'] >= 30 ? 'high' : 'normal', $n, 85,
        )];
    }

    /** @return list<array<string,mixed>> */
    private function frontDesk(InsightContext $c): array
    {
        if (! $c->can('billing.manage')) {
            return [];
        }

        $n = Visit::query()->whereIn('status', [VisitStatus::Pending->value, VisitStatus::Ongoing->value])
            ->where('stage', VisitStage::Payment->value)->count();

        return $n === 0 ? [] : [$this->item('unpaid-visits', $n === 1 ? '1 visit waiting to pay' : "{$n} visits waiting to pay", 'An invoice is raised and the balance is open.', 'admin.visits.index', null, 'normal', $n, 60)];
    }

    /** @return list<array<string,mixed>> */
    private function lab(InsightContext $c): array
    {
        if (! $c->can('lab.process')) {
            return [];
        }

        $q = LabOrder::query()->whereIn('status', LabBench::outstandingStatuses());
        $n = (clone $q)->count();
        if ($n === 0) {
            return [];
        }
        $old = (clone $q)->where('created_at', '<=', $c->now->copy()->subHours(4))->exists();

        return [$this->item('lab-queue', $n === 1 ? '1 lab order waiting' : "{$n} lab orders waiting", $old ? 'Some have waited over 4 hours.' : 'Collected, processing or not yet started.', 'admin.lab-orders.index', null, $old ? 'high' : 'normal', $n, 66)];
    }

    /**
     * @return array{kind:string,title:string,sub:string,route:string,uuid:?string,urgency:string,count:?int,_p:int}
     */
    private function item(string $kind, string $title, string $sub, string $route, ?string $uuid, string $urgency, ?int $count, int $priority): array
    {
        return ['kind' => $kind, 'title' => $title, 'sub' => $sub, 'route' => $route, 'uuid' => $uuid, 'urgency' => $urgency, 'count' => $count, '_p' => $priority];
    }
}
