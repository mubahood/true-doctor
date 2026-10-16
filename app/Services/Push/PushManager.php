<?php

namespace App\Services\Push;

use InvalidArgumentException;

/**
 * Chooses the push driver from config/push.php (PUSH_DRIVER). Not bound in a
 * service provider on purpose: a standalone copy only has the classes it
 * shipped with, and this needs no registration.
 */
final class PushManager
{
    public function driverName(): string
    {
        return (string) config('push.driver', 'null');
    }

    public function transport(): PushTransport
    {
        $name = $this->driverName();
        $class = config("push.drivers.{$name}");
        if (! is_string($class) || ! is_a($class, PushTransport::class, true)) {
            throw new InvalidArgumentException("Push driver [{$name}] is not configured.");
        }

        return app($class);
    }

    /** True when a push can really reach a phone (what `features.push` should advertise). */
    public function delivers(): bool
    {
        try {
            return $this->transport()->delivers();
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
