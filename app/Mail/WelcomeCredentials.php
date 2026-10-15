<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeCredentials extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword
    ) {}

    public function envelope(): Envelope
    {
        $hospital = \App\Support\HospitalBrand::forUser($this->user)->name();

        return new Envelope(
            subject: $hospital !== null ? "Your {$hospital} staff account" : 'Your True-Doctor staff account',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome-credentials');
    }
}
