<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Where a person is signed in, and ending it: the web's sessions and the
 * app's tokens, for the web page and the API alike.
 */
class AccountSecurityService
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Sign out every web session and every app except, optionally, the token
     * making this request. The web's own session is kept by the caller, which
     * renews its mark after this.
     */
    public function signOutEverywhere(User $user, ?int $keepTokenId = null): void
    {
        $user->forceFill([
            'signed_out_everywhere_at' => now(),
            // A "keep me signed in" cookie carries this token: changing it ends them all.
            'remember_token' => Str::random(60),
        ])->save();

        $user->tokens()->when($keepTokenId !== null, fn ($q) => $q->whereKeyNot($keepTokenId))->delete();
        $this->twoFactor->forgetDevices($user);
    }

    /**
     * The apps and devices signed in with a token, newest use first.
     *
     * @return list<array{id: int, name: string, last_used_at: string|null, created_at: string|null, current: bool}>
     */
    public function devices(User $user, ?int $currentTokenId = null): array
    {
        $out = [];
        foreach ($user->tokens()->orderByDesc('last_used_at')->orderByDesc('id')->get() as $t) {
            /** @var PersonalAccessToken $t */
            $out[] = [
                'id' => (int) $t->getKey(),
                'name' => (string) $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                'current' => $currentTokenId !== null && (int) $t->getKey() === $currentTokenId,
            ];
        }

        return $out;
    }

    /** Sign one device out. False when it is not this person's. */
    public function revokeDevice(User $user, int $tokenId): bool
    {
        return $user->tokens()->whereKey($tokenId)->delete() > 0;
    }
}
