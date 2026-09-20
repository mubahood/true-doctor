<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as Base;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Queued for the same reason as `VerifyEmailNotification`: a mail provider
 * having a bad day must not turn "I forgot my password" into a 500.
 */
class ResetPasswordNotification extends Base implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $minutes = (int) config('auth.passwords.users.expire', 60);
        $expiresIn = $minutes % 60 === 0 ? ($minutes / 60).' hours' : $minutes.' minutes';

        return (new MailMessage)
            ->subject('Reset Your True-Doctor Password')
            ->view('emails.reset-password', [
                'user' => $notifiable,
                'resetUrl' => $url,
                'expiresIn' => $expiresIn,
            ]);
    }
}
