<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\PushChannel;
use Carbon\CarbonInterface;

/**
 * The one shape every notification the apps and the web bell show has.
 *
 * Stored in the `notifications.data` JSON (so the web bell keeps reading
 * `type`, `title`, `message` and each class's own uuid key) and returned
 * top-level by GET /api/v1/notifications.
 *
 *   category        (App\Models\NotificationPreference::CATEGORIES) clinical | ward | schedule | stock | billing | sync | account | system
 *   priority        low | normal | urgent
 *   title           short, safe to show on a lock screen
 *   message         the text the web bell has always shown (may name a patient)
 *   public_message  safe for a lock screen or a push: never a name or number
 *   private_message what the inbox shows after sign-in and PIN (may name a patient)
 *   route           the web route name the app maps to a screen (admin.lab-orders.index)
 *   entity/entity_uuid  the record it is about (lab_order, stock_item, appointment)
 *   dedupe_key      the same thing twice has the same key
 *   expires_at      after this it is no longer worth showing
 *
 * A class uses the trait and supplies `legacyData()` (its old keys) and the
 * small methods below; the trait builds toArray() and the push payload.
 */
trait AppNotification
{
    /** The keys this notification has always stored (type, title, message, <thing>_uuid). @return array<string,mixed> */
    abstract protected function legacyData(): array;

    abstract protected function category(): string;

    /** Text with no name or number in it, for a lock screen or push. */
    abstract protected function publicMessage(): string;

    protected function priority(): string
    {
        return 'normal';
    }

    /** What the inbox shows once the person is in. Defaults to the web bell's message. */
    protected function privateMessage(): ?string
    {
        return (string) ($this->legacyData()['message'] ?? $this->publicMessage());
    }

    protected function routeName(): ?string
    {
        return null;
    }

    protected function entityType(): ?string
    {
        return null;
    }

    protected function entityUuid(): ?string
    {
        return null;
    }

    protected function dedupeKey(): ?string
    {
        $uuid = $this->entityUuid();

        return $uuid === null ? null : ($this->entityType() ?? 'item').':'.$uuid.':'.($this->legacyData()['type'] ?? 'note');
    }

    protected function expiresAt(): ?CarbonInterface
    {
        return null;
    }

    /** @return array<string,mixed> the standard fields */
    public function standardPayload(): array
    {
        $legacy = $this->legacyData();

        return [
            'category' => $this->category(),
            'priority' => $this->priority(),
            'public_message' => $this->publicMessage(),
            'private_message' => $this->privateMessage(),
            'route' => $this->routeName(),
            'entity' => $this->entityType(),
            'entity_uuid' => $this->entityUuid(),
            'dedupe_key' => $this->dedupeKey(),
            'expires_at' => $this->expiresAt()?->toIso8601String(),
        ] + ['title' => $legacy['title'] ?? 'Notification'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->legacyData() + $this->standardPayload();
    }

    /**
     * What a push carries: the title and the public text, and enough to open
     * the right screen. Never the private message.
     *
     * @return array<string,mixed>
     */
    public function toPush(object $notifiable): array
    {
        $p = $this->standardPayload();

        return [
            'title' => $p['title'],
            'body' => $p['public_message'],
            'category' => $p['category'],
            'priority' => $p['priority'],
            'route' => $p['route'],
            'entity' => $p['entity'],
            'entity_uuid' => $p['entity_uuid'],
            'dedupe_key' => $p['dedupe_key'],
        ];
    }

    /** @param  array<int,string|class-string>  $channels @return array<int,string|class-string> */
    protected function withPush(array $channels): array
    {
        return [...$channels, PushChannel::class];
    }
}
