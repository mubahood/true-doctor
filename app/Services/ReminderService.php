<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\User;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use App\Support\SubscriptionState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What this person should be reminded of between two moments. One source for
 * GET /api/v1/reminders/upcoming and any future server-side sender, so the
 * phone's own planner, the server's push and the web agree on what is due.
 *
 * Every item has a stable `key` (the same reminder always has the same key,
 * and the phone uses the same keys for what it plans itself), a generic
 * `title_public` that is safe on a lock screen, and a `body_private` that may
 * name a patient and is only shown once the person is in.
 *
 * Filtered by the person's permissions and their hospital: nothing here is
 * computed for a section they could not open.
 */
class ReminderService
{
    public const DAY_BEFORE_AT = '18:00';

    public const DIGEST_AT = '08:00';

    public const LICENCE_AT = '09:00';

    public const SOON_MINUTES = 60;

    /** @return array<int,array<string,mixed>> */
    public function upcoming(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $hospital = $user->hospital;
        if ($hospital === null) {
            return [];
        }
        $tz = $this->zone($hospital);
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);

        $items = [
            ...$this->appointments($user, $hospital, $tz, $from, $to),
            ...$this->stock($user, $tz, $from, $to),
            ...$this->licence($user, $hospital, $tz, $from, $to),
        ];

        usort($items, fn ($a, $b) => strcmp($a['fire_at'], $b['fire_at']));

        return $items;
    }

    public function zone(Hospital $hospital): string
    {
        $tz = (string) ($hospital->timezone ?: config('app.timezone'));

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    /** @return array<int,array<string,mixed>> */
    private function appointments(User $user, Hospital $hospital, string $tz, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $user->can('appointments.view')) {
            return [];
        }

        // The day-before reminder can fire up to ~a day ahead of the visit, so
        // look a little past the window on both sides, then keep what falls inside.
        $rows = Appointment::query()
            ->with('patient')
            ->where('hospital_id', $hospital->id)
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
            ->whereBetween('scheduled_at', [$from->subHours(2), $to->addDays(2)])
            ->when(! $user->can('appointments.manage'), fn ($q) => $q->where('doctor_user_id', $user->id))
            ->orderBy('scheduled_at')
            ->limit(500)
            ->get();

        $out = [];
        foreach ($rows as $a) {
            $at = CarbonImmutable::instance($a->scheduled_at);
            $who = $a->patient?->full_name;
            $follow = $a->isFollowUp();
            $kinds = [
                'day' => [
                    $at->setTimezone($tz)->subDay()->setTimeFromTimeString(self::DAY_BEFORE_AT),
                    $follow ? 'Follow-up tomorrow' : 'Appointment tomorrow',
                    ($follow ? 'Follow-up' : 'Appointment').' tomorrow at '.$at->setTimezone($tz)->format('H:i').($who ? " with {$who}" : '').'.',
                ],
                'soon' => [
                    $at->subMinutes(self::SOON_MINUTES),
                    $follow ? 'Follow-up in an hour' : 'Appointment in an hour',
                    ($follow ? 'Follow-up' : 'Appointment').' at '.$at->setTimezone($tz)->format('H:i').($who ? " with {$who}" : '').'.',
                ],
            ];
            foreach ($kinds as $kind => [$fire, $title, $body]) {
                if ($fire->lt($from) || $fire->gt($to) || $fire->gte($at)) {
                    continue;
                }
                $out[] = $this->item("appt:{$a->uuid}:{$kind}", 'schedule', 'normal', $fire, $title, $body, 'admin.appointments.index', 'appointment', $a->uuid, $at);
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function stock(User $user, string $tz, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $user->can('pharmacy.view')) {
            return [];
        }

        // One digest, at the next 08:00 in the window.
        $fire = $from->setTimezone($tz)->setTimeFromTimeString(self::DIGEST_AT);
        if ($fire->lt($from)) {
            $fire = $fire->addDay();
        }
        if ($fire->gt($to)) {
            return [];
        }

        $risk = (new StockAlertBoard)->atRisk();
        $low = $risk['out'] + $risk['low'];
        $expiring = $risk['expiring'] + $risk['expired'];
        if ($low === 0 && $expiring === 0) {
            return [];
        }

        $parts = array_filter([
            $low > 0 ? "{$low} low or out of stock" : null,
            $expiring > 0 ? "{$expiring} expiring or expired" : null,
        ]);

        return [$this->item(
            'stock:digest:'.$fire->format('Y-m-d'), 'stock', $risk['out'] + $risk['expired'] > 0 ? 'urgent' : 'normal', $fire,
            'Stock needs a look', implode(', ', $parts).'.', 'admin.stock.alerts', 'stock_digest', null, $fire->addHours(16),
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private function licence(User $user, Hospital $hospital, string $tz, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $user->can('manage-settings')) {
            return [];
        }

        if (Edition::isSaas()) {
            $sub = app(SubscriptionState::class)->current($hospital);
            $end = $sub?->status === SubscriptionStatus::Trialing ? $sub->trial_ends_at : $sub?->ends_at;
            $trial = $sub?->status === SubscriptionStatus::Trialing;
            $route = 'admin.subscription.index';
            $noun = $trial ? 'Your free trial' : 'Your subscription';
        } else {
            $end = app(InstalledLicence::class)->updatesUntil();
            $route = 'admin.system.index';
            $noun = 'Your updates window';
        }
        if ($end === null) {
            return [];
        }

        $endDay = CarbonImmutable::instance($end)->setTimezone($tz)->startOfDay();
        $out = [];
        foreach ([3, 1, 0] as $days) {
            $fire = $endDay->subDays($days)->setTimeFromTimeString(self::LICENCE_AT);
            if ($fire->lt($from) || $fire->gt($to)) {
                continue;
            }
            $when = match ($days) {
                0 => 'ends today',
                1 => 'ends tomorrow',
                default => "ends in {$days} days",
            };
            $out[] = $this->item(
                "licence:{$days}:".$endDay->format('Y-m-d'), 'account', $days <= 1 ? 'urgent' : 'normal', $fire,
                "{$noun} {$when}", 'Open the subscription page to keep your hospital running.', $route, 'subscription', null, $endDay->endOfDay(),
            );
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function item(string $key, string $category, string $priority, CarbonImmutable $fire, string $title, string $body, string $route, string $entity, ?string $uuid, ?CarbonInterface $expires): array
    {
        return [
            'key' => $key,
            'category' => $category,
            'priority' => $priority,
            'fire_at' => $fire->toIso8601String(),
            // Generic: the same words a lock screen may show. Names go in body_private.
            'title_public' => $title,
            'body_private' => $body,
            'route' => $route,
            'entity' => $entity,
            'uuid' => $uuid,
            'expires_at' => $expires?->toIso8601String(),
        ];
    }
}
