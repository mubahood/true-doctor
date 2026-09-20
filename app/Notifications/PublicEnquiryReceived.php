<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody filled in the contact form.
 *
 * Goes to whoever `mail.enquiries_to` names. Everything in it came from a
 * public form, so nothing is trusted: the reply-to is set to the address
 * given, but the body says plainly that it is unverified, and the mail is
 * plain text so a pasted link cannot render as anything but a link.
 *
 * Queued, like every notification here. Sending inside the request means a
 * mail provider having a bad afternoon turns somebody's enquiry into a 500 —
 * on the one page whose entire job is to let a stranger reach us.
 */
class PublicEnquiryReceived extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string,mixed> $enquiry */
    public function __construct(private readonly array $enquiry) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = (string) ($this->enquiry['name'] ?? 'Someone');
        $email = (string) ($this->enquiry['email'] ?? '');

        $message = (new MailMessage)
            ->subject('Website enquiry — '.$name)
            ->greeting('New enquiry from the website')
            ->line('**From:** '.$name.' <'.$email.'>');

        foreach ([
            'Hospital' => $this->enquiry['hospital'] ?? null,
            'Phone' => $this->enquiry['phone'] ?? null,
            'Size' => $this->enquiry['size'] ?? null,
        ] as $label => $value) {
            if (filled($value)) {
                $message->line('**'.$label.':** '.$value);
            }
        }

        $message
            ->line('---')
            ->line((string) ($this->enquiry['message'] ?? ''))
            ->line('---')
            ->line('Sent from the public contact form. The address above was typed by the sender and has not been verified.');

        if ($email !== '') {
            $message->replyTo($email, $name);
        }

        return $message;
    }
}
