<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as Base;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The "confirm your email address" message, queued.
 *
 * Laravel's built-in `VerifyEmail` is NOT queued, so it talks to the SMTP
 * server inside the web request. Every one of the other notifications in this
 * directory implements `ShouldQueue` — and the difference matters: a mail
 * provider that refuses a login, times out, or greylists produced a 500 and a
 * stack trace for somebody who had clicked "resend the email".
 *
 * Queued, the request returns immediately, the send is retried by the worker,
 * and a genuine failure lands in `failed_jobs` where Horizon shows it to
 * somebody who can do something about it.
 *
 * The signed URL and its expiry are Laravel's; the email itself is the same
 * shell as every other one staff receive (emails/_header), in their
 * hospital's name and colour when it has set them.
 */
class VerifyEmailNotification extends Base implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts over a few minutes.
     *
     * A refused login will fail all three and that is the point: it belongs in
     * `failed_jobs` quickly, not retried for an hour. A transient timeout
     * usually clears inside the first backoff.
     */
    public int $tries = 3;

    /** @return array<int,int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 60];
    }

    /** The hospital's own email, like every other one staff receive (emails/_header). */
    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Confirm your email address — '.(\App\Support\HospitalBrand::forUser($notifiable)->name() ?? 'True-Doctor'))
            ->view('emails.verify-email', [
                'user' => $notifiable,
                // The base class's signed URL: its expiry and any createUrlUsing callback hold.
                'verifyUrl' => $this->verificationUrl($notifiable),
                'expiresIn' => $minutes % 60 === 0 ? ($minutes / 60).' hour'.($minutes === 60 ? '' : 's') : $minutes.' minutes',
            ]);
    }
}
