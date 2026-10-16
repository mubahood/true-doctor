<?php

namespace App\Notifications;

use App\Models\LabOrder;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Concerns\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Fired when a lab order is completed — the ordering doctor is notified. */
class LabResultReady extends Notification implements ShouldQueue
{
    use AppNotification, Queueable;

    public function __construct(public readonly LabOrder $order) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return $this->withPush(['database', SmsChannel::class]);
    }

    /** @return array<string,mixed> */
    protected function legacyData(): array
    {
        return [
            'type' => 'lab_result_ready',
            'title' => 'Lab results ready',
            'message' => "Lab results are ready for {$this->order->patient?->full_name}.",
            'lab_order_uuid' => $this->order->uuid,
        ];
    }

    protected function category(): string
    {
        return 'clinical';
    }

    protected function priority(): string
    {
        return 'urgent';
    }

    protected function publicMessage(): string
    {
        return 'A lab result is ready.';
    }

    protected function routeName(): ?string
    {
        return 'admin.lab-orders.index';
    }

    protected function entityType(): ?string
    {
        return 'lab_order';
    }

    protected function entityUuid(): ?string
    {
        return $this->order->uuid;
    }

    public function toSms(object $notifiable): string
    {
        return "Lab results ready for {$this->order->patient?->full_name}.";
    }
}
