<?php

namespace App\Services\Push;

/**
 * Whatever actually delivers a push to a phone (FCM, APNs, a relay).
 *
 * Adding a provider is a class that implements this plus a line in
 * config/push.php; nothing else in the system knows who delivers.
 */
interface PushTransport
{
    /**
     * @param  string  $token  the device's token for this provider
     * @param  string  $platform  android | ios | web
     * @param  array<string,mixed>  $payload  title, body, category, priority, route, entity, entity_uuid, dedupe_key
     * @return bool true when handed to the provider; false when it was dropped or refused
     */
    public function send(string $token, string $platform, array $payload): bool;

    /** Whether a message can really reach a phone with this driver. */
    public function delivers(): bool;
}
