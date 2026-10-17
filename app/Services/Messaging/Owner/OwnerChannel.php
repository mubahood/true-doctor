<?php

namespace App\Services\Messaging\Owner;

use App\Models\Hospital;
use App\Models\User;

/**
 * A way to reach a hospital's owner with their daily summary (roadmap 09 §2).
 *
 * Email and SMS today. WhatsApp (roadmap 03, Phase 3) is one more class that
 * implements this and one more line in OwnerSummaryService::CHANNELS; nothing
 * that composes or schedules the summary changes.
 */
interface OwnerChannel
{
    /** The stored name: 'email', 'sms'. */
    public function name(): string;

    /** For the settings screen: "Email", "Text message". */
    public function label(): string;

    /** Where a message to this person would go, or null when there is nowhere (no email, no phone). */
    public function address(User $owner, Hospital $hospital): ?string;

    /**
     * The body as this channel carries it: a text message is one short paragraph, an email keeps its lines.
     *
     * @param  list<string>  $lines
     */
    public function format(string $subject, array $lines, string $link): string;

    /**
     * Send it. Null when it went; otherwise what went wrong, in words for the owner.
     *
     * @throws \Throwable only for a fault worth reporting; the caller records it as failed
     */
    public function send(Hospital $hospital, string $to, string $subject, string $body): ?string;
}
