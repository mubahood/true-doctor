<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\OrderStatus;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Support\CurrentHospital;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Orders changed after they were placed (docs/orders.md, "Changing an
 * order"): on two open orders a doctor handed the work to a colleague and
 * added to the instructions, so the order dialog shows "changed by" and the
 * audit log shows the before and after.
 *
 * Only orders that still take changes (pending or in progress, raised by hand
 * rather than by a lab request or a plan). Idempotent: a hospital with an
 * edited order is left as it is.
 */
final class OrderEdits
{
    public static function run(Hospital $hospital): void
    {
        $context = app(CurrentHospital::class);
        $was = $context->id();
        $context->set($hospital->id);

        try {
            if (Order::query()->whereNotNull('edited_at')->exists()) {
                return;
            }

            $clinicians = User::query()->where('hospital_id', $hospital->id)->where('is_active', true)
                ->clinicians()->orderBy('id')->get();
            if ($clinicians->count() < 2) {
                return;
            }

            $orders = Order::query()->with('visit')
                ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::InProgress->value])
                ->whereNull('subject_type')
                ->whereIn('type', ['consultation', 'procedure'])
                ->latest('id')->limit(2)->get();

            foreach ($orders as $order) {
                if ($order->visit === null || ! $order->visit->isOpen()) {
                    continue;
                }
                $to = $clinicians->first(fn (User $u) => (int) $u->id !== (int) $order->assigned_to);
                $by = $clinicians->first(fn (User $u) => (int) $u->id === (int) $order->assigned_to) ?? $clinicians->first();
                if ($to === null || $by === null) {
                    continue;
                }

                Auth::setUser($by);
                try {
                    app(OrderService::class)->edit($order, [
                        'assigned_to' => (int) $to->id,
                        'notes' => trim(($order->notes ? $order->notes.' ' : '').'Handed over to '.$to->name.' for the afternoon.'),
                    ], (int) $by->id);
                } catch (Throwable) {
                    continue; // an order that no longer takes changes is simply skipped
                }
            }
        } finally {
            Auth::forgetUser();
            $context->set($was);
        }
    }
}
