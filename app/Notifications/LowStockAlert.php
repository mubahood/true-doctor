<?php

namespace App\Notifications;

use App\Models\StockItem;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Fired when a stock item drops to/below its reorder level. */
class LowStockAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly StockItem $item) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database', SmsChannel::class];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'low_stock',
            'title' => 'Low stock',
            'message' => "{$this->item->name} is low ({$this->qty()} {$this->item->unit} left).",
            'stock_item_uuid' => $this->item->uuid,
        ];
    }

    public function toSms(object $notifiable): string
    {
        return "Low stock: {$this->item->name} — {$this->qty()} {$this->item->unit} left.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Low stock alert')
            ->line("{$this->item->name} has dropped to {$this->qty()} {$this->item->unit} (reorder at {$this->item->reorder_level}).");
    }

    private function qty(): string
    {
        return rtrim(rtrim((string) $this->item->current_quantity, '0'), '.');
    }
}
