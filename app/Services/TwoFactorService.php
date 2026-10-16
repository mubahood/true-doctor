<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Demo;
use App\Support\TwoFactor\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two-step sign-in: the rules, for the web and the API alike.
 *
 * Turning it on is two steps (a secret is offered, then a code from the app
 * proves it was saved), so nobody is locked out by a QR code they never
 * scanned. A used code cannot be used again, a recovery code works once,
 * and a trusted browser is remembered for `auth.two_factor.trust_days`.
 */
class TwoFactorService
{
    public const RECOVERY_CODES = 8;

    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function isEnabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    /**
     * Must this person use it? Owners, administrators and the super admin,
     * when the installation enforces it. Never a demo account: its password
     * is printed on the page, and a second step would only lock visitors out.
     */
    public function isRequired(User $user): bool
    {
        return (bool) config('auth.two_factor.enforce')
            && in_array($user->role, (array) config('auth.two_factor.required_roles', []), true)
            && ! Demo::isDemoUser($user);
    }

    /** Required and not yet set up: the person is held on the set-up page. */
    public function mustSetUp(User $user): bool
    {
        return $this->isRequired($user) && ! $this->isEnabled($user);
    }

    /**
     * Offer a new secret (not yet in force). Calling it again replaces an
     * unconfirmed one; an enabled one is left alone.
     *
     * @return array{secret:string, uri:string}
     */
    public function begin(User $user): array
    {
        if ($this->isEnabled($user)) {
            throw new \DomainException('Two-step sign-in is already on. Turn it off first to start again.');
        }

        $secret = Totp::newSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $user->email)];
    }

    /** The secret offered by {@see begin()} and its address, while it is still pending. */
    public function pending(User $user): ?array
    {
        if ($this->isEnabled($user) || blank($user->two_factor_secret)) {
            return null;
        }

        return ['secret' => (string) $user->two_factor_secret, 'uri' => Totp::uri((string) $user->two_factor_secret, (string) $user->email)];
    }

    /**
     * A code from the app proves the secret was saved: turn it on and return
     * the recovery codes, shown once.
     *
     * @return list<string>|null null when the code is wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($this->isEnabled($user) || blank($user->two_factor_secret)) {
            return null;
        }

        $step = Totp::matchingStep((string) $user->two_factor_secret, $code);
        if ($step === null) {
            return null;
        }

        [$plain, $hashed] = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => $hashed,
        ])->save();

        return $plain;
    }

    /**
     * Check a code typed at sign-in: a current authenticator code that has not
     * been used yet, or an unused recovery code (which is then spent). Locked
     * so two requests cannot spend the same code.
     */
    public function verify(User $user, string $code): bool
    {
        if (! $this->isEnabled($user)) {
            return false;
        }

        return DB::transaction(function () use ($user, $code) {
            /** @var User $fresh */
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $step = Totp::matchingStep((string) $fresh->two_factor_secret, $code);
            if ($step !== null) {
                if ($fresh->two_factor_last_step !== null && $step <= $fresh->two_factor_last_step) {
                    return false; // already used: a code read over a shoulder cannot be replayed
                }
                $fresh->forceFill(['two_factor_last_step' => $step])->save();
                $user->setRawAttributes($fresh->getAttributes(), true);

                return true;
            }

            $hash = $this->hashRecovery($code);
            $codes = (array) ($fresh->two_factor_recovery_codes ?? []);
            $index = array_search($hash, $codes, true);
            if ($hash === null || $index === false) {
                return false;
            }

            unset($codes[$index]);
            $fresh->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
            $user->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    /** How many recovery codes are left, so a screen can say "make new ones". */
    public function recoveryCodesLeft(User $user): int
    {
        return count((array) ($user->two_factor_recovery_codes ?? []));
    }

    /** @return list<string> new codes, shown once; the old ones stop working */
    public function regenerateRecoveryCodes(User $user): array
    {
        [$plain, $hashed] = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $hashed])->save();

        return $plain;
    }

    /** Turn it off: the secret, the codes and every trusted browser go. */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        $this->forgetDevices($user);
    }

    // ── Trusted browsers ───────────────────────────────────────────

    /** Remember this browser; returns the token for its cookie. */
    public function trust(User $user, ?string $label, ?string $ip): string
    {
        $token = Str::random(64);
        TrustedDevice::create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $token),
            'label' => $label !== null ? Str::limit($label, 117) : null,
            'ip' => $ip,
            'last_used_at' => now(),
            'expires_at' => now()->addDays((int) config('auth.two_factor.trust_days', 30)),
        ]);

        return $token;
    }

    public function isTrusted(User $user, ?string $token): bool
    {
        if (blank($token)) {
            return false;
        }

        $device = TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('token_hash', hash('sha256', (string) $token))
            ->where('expires_at', '>', now())
            ->first();

        $device?->forceFill(['last_used_at' => now()])->save();

        return $device !== null;
    }

    public function forgetDevices(User $user): void
    {
        TrustedDevice::query()->where('user_id', $user->getKey())->delete();
    }

    // ── The API's challenge ────────────────────────────────────────

    /** A short-lived, sealed ticket that says "this password was right". */
    public function challengeFor(User $user, string $device): string
    {
        return Crypt::encryptString(json_encode([
            'u' => $user->getKey(),
            'd' => Str::limit($device, 100, ''),
            'x' => now()->addMinutes((int) config('auth.two_factor.challenge_minutes', 10))->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{user:User, device:string}|null */
    public function openChallenge(string $challenge): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($challenge), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data) || ($data['x'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $user = User::query()->find($data['u'] ?? 0);

        return $user === null ? null : ['user' => $user, 'device' => (string) ($data['d'] ?? 'api')];
    }

    // ── Internals ──────────────────────────────────────────────────

    /** @return array{0: list<string>, 1: list<string>} plain codes and their hashes */
    private function newRecoveryCodes(): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $chars = '';
            for ($j = 0; $j < 10; $j++) {
                $chars .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $plain[] = substr($chars, 0, 5).'-'.substr($chars, 5);
        }

        return [$plain, array_map(fn ($c) => (string) $this->hashRecovery($c), $plain)];
    }

    private function hashRecovery(string $code): ?string
    {
        $normal = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return strlen($normal) === 10 ? hash('sha256', $normal) : null;
    }
}
