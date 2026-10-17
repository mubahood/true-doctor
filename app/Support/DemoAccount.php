<?php

namespace App\Support;

use App\Models\User;

/**
 * One seeded demo login, as shown on the local sign-in rail. Built from a real
 * user row so the rail can never advertise an account that does not exist.
 */
final readonly class DemoAccount
{
    public function __construct(
        public string $email,
        public string $role,
        public ?string $tenant,
        public string $tag,
        public string $kind,
    ) {}

    public static function fromUser(User $user): self
    {
        $hospital = $user->hospital;

        return new self(
            email: (string) $user->email,
            role: $user->role_label,
            tenant: $hospital?->name,
            // The demo's own hospital is "A", the isolation tenant "B"; the SaaS account has none.
            tag: $hospital === null ? 'Central' : (str_ends_with((string) $user->email, '.b@test.com') ? 'B' : 'A'),
            kind: match (true) {
                $hospital === null => 'super',
                str_ends_with((string) $user->email, '.b@test.com') => 'b',
                default => 'a',
            },
        );
    }
}
