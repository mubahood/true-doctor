<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\PatientMessage;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\SmsTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Send one patient message (PatientMessenger wrote it down).
 *
 * Claimed first (queued → sending, in one conditional update), so two workers
 * can never send it twice. The credit is taken before the gateway is called
 * and given back if the message finally fails, so a balance can never go
 * below zero and a hospital never pays for a message nobody received.
 */
class SendPatientMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> seconds before each retry */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $messageId) {}

    public function handle(SmsTransport $sms, MessageCredits $credits): void
    {
        $claimed = PatientMessage::query()->withoutGlobalScopes()
            ->whereKey($this->messageId)
            ->where('status', MessageStatus::Queued->value)
            ->where(fn ($q) => $q->whereNull('send_after')->orWhere('send_after', '<=', Carbon::now()))
            ->update(['status' => MessageStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => Carbon::now()]);

        /** @var PatientMessage|null $message */
        $message = PatientMessage::query()->withoutGlobalScopes()->find($this->messageId);
        if ($message === null) {
            return;
        }
        if ($claimed !== 1) {
            // Still waiting out the quiet hours (a worker ran it early): come back then.
            if ($message->status === MessageStatus::Queued && $message->send_after?->isFuture()) {
                $this->release(max(1, (int) Carbon::now()->diffInSeconds($message->send_after)));
            }

            return;
        }

        $metered = MessagingSettings::metered();
        if ($metered && $message->attempts === 1) {
            try {
                $credits->post($message->hospital_id, 'send', -$message->segments, $message->uuid, $message->id);
            } catch (\RuntimeException) {
                $message->forceFill(['status' => MessageStatus::Skipped, 'skip_reason' => 'no_credit'])->save();

                return;
            }
        }

        $result = $sms->send((string) $message->to_phone, $message->body);

        if ($result->ok) {
            $message->forceFill([
                'status' => MessageStatus::Sent, 'provider' => $sms->name(), 'provider_id' => $result->providerId,
                'cost' => $result->cost, 'error' => null, 'sent_at' => Carbon::now(),
            ])->save();

            return;
        }

        $message->forceFill(['provider' => $sms->name(), 'error' => $result->error])->save();

        if ($result->retryable && $this->attempts() < $this->tries) {
            $message->forceFill(['status' => MessageStatus::Queued])->save();
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        $this->giveUp($message, $credits, $metered);
    }

    /** The queue gave up on it (an exception, every try spent). */
    public function failed(?\Throwable $e): void
    {
        $message = PatientMessage::query()->withoutGlobalScopes()->find($this->messageId);
        if ($message !== null && in_array($message->status, [MessageStatus::Queued, MessageStatus::Sending], true)) {
            $message->forceFill(['error' => $message->error ?? ($e?->getMessage() ?: 'Could not be sent.')])->save();
            $this->giveUp($message, app(MessageCredits::class), MessagingSettings::metered());
        }
    }

    private function giveUp(PatientMessage $message, MessageCredits $credits, bool $metered): void
    {
        $message->forceFill(['status' => MessageStatus::Failed])->save();
        if ($metered) {
            $credits->post($message->hospital_id, 'refund', $message->segments, $message->uuid, $message->id, null, 'Not delivered to the gateway');
        }
    }
}
