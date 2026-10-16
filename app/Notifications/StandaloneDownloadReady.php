<?php

namespace App\Notifications;

use App\Models\StandaloneLicence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * A buyer's private way into their downloads (docs/proposals/standalone-edition.md §7).
 *
 * Sent after a verified payment, a renewal, a licence issued by hand, or when
 * the buyer asks for a link again. The link is made when the email is sent,
 * not when it was queued, so a slow queue never delivers one that has already
 * expired.
 */
class StandaloneDownloadReady extends Notification implements ShouldQueue
{
    use Queueable;

    public const LINK_HOURS = 24;

    /** @param 'purchased'|'renewed'|'issued'|'link' $reason */
    public function __construct(public readonly string $licenceId, public readonly string $reason = 'link') {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $licence = StandaloneLicence::find($this->licenceId);
        $link = URL::temporarySignedRoute('standalone.portal.claim', now()->addHours(self::LINK_HOURS), ['licence' => $this->licenceId]);
        $until = $licence?->updates_until->format('d M Y');

        $message = (new MailMessage)->greeting('Hello'.($licence ? ' '.$licence->customer_name : '').',');

        return (match ($this->reason) {
            'purchased' => $message->subject('Your True-Doctor download is ready')
                ->line('Thank you — your payment is confirmed and your licence is ready.')
                ->line('Your private page has the installation package, your licence file and the step-by-step setup guide, together.')
                ->action('Open my downloads', $link)
                ->line("New releases are included until {$until}."),
            'renewed' => $message->subject('Your True-Doctor updates are renewed')
                ->line("Your payment is confirmed. New releases are now included until {$until}.")
                ->action('Open my downloads', $link),
            'issued' => $message->subject('Your True-Doctor licence')
                ->line('Your True-Doctor licence has been issued.')
                ->line('Your private page has the installation package, your licence file and the step-by-step setup guide, together.')
                ->action('Open my downloads', $link)
                ->line("New releases are included until {$until}."),
            default => $message->subject('Your True-Doctor download link')
                ->line('Here is the private link to your True-Doctor downloads.')
                ->action('Open my downloads', $link),
        })->line('The link works for '.self::LINK_HOURS.' hours. You can always ask for a new one from the downloads page.')
            ->line(\App\Support\SupportContact::platform()->mailLine('standalone.install', ['licence' => (string) $licence?->id]));
    }
}
