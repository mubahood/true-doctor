<?php

namespace App\Notifications;

use App\Models\LabOrder;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Fired when a lab order is completed — the ordering doctor is notified. */
class LabResultReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LabOrder $order) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database', SmsChannel::class];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lab_result_ready',
            'title' => 'Lab results ready',
            'message' => "Lab results are ready for {$this->order->patient?->full_name}.",
            'lab_order_uuid' => $this->order->uuid,
        ];
    }

    public function toSms(object $notifiable): string
    {
        return "Lab results ready for {$this->order->patient?->full_name}.";
    }
}
