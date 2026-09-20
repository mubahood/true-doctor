<?php

namespace App\Services;

use App\Mail\WelcomeCredentials;
use App\Models\User;
use App\Support\PlanLimit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Staff-account lifecycle — the single home for the security-critical bits of
 * user management that used to be duplicated between the classic
 * UserController and the Livewire modal (docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md
 * Part V rule 4):
 *
 *  - tenant scoping (User has no BelongsToHospital global scope, because
 *    super-admin rows with hospital_id = null live in the same table);
 *  - the assignable-role whitelist (no in-tenant privilege escalation);
 *  - the subscription plan's staff seat cap;
 *  - temporary password + password_change_required on create;
 *  - hospital assignment (only a super-admin may mint another super-admin);
 *  - avatar store/replace (the old file is dropped only after the row is saved);
 *  - the queued welcome email.
 */
class StaffService
{
    public function __construct(private readonly AvatarService $avatars, private readonly PlanLimit $planLimit) {}

    /**
     * Users $actor may see and manage: everyone for a super-admin, their own
     * hospital otherwise.
     *
     * @return Builder<User>
     */
    public function manageableBy(User $actor): Builder
    {
        return User::query()->manageableBy($actor);
    }

    /** Can $actor manage this particular account? */
    public function manages(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin() || ($target->hospital_id !== null && $target->hospital_id === $actor->hospital_id);
    }

    /**
     * Roles $actor may assign (never `super_admin` for a tenant admin).
     *
     * @return list<string>
     */
    public function assignableRoles(?User $actor): array
    {
        return User::assignableRolesFor($actor);
    }

    /**
     * Throw PlanLimitExceededException if the hospital's plan has no free seat.
     *
     * @throws \App\Exceptions\PlanLimitExceededException
     */
    public function assertSeatAvailable(): void
    {
        $this->planLimit->assertCanCreate('staff');
    }

    /**
     * Create a staff account for $actor's hospital and email its credentials.
     *
     * @param  array<string, mixed>  $data  validated attributes (name, email, role, phone, bio, is_active)
     * @return array{user: User, emailed: bool}
     *
     * @throws \App\Exceptions\PlanLimitExceededException
     */
    public function create(User $actor, array $data, UploadedFile|TemporaryUploadedFile|null $avatar = null): array
    {
        $this->assertSeatAvailable();

        $role = $data['role'] ?? null;
        $plain = Str::password(16);

        $data['password'] = Hash::make($plain);
        $data['password_change_required'] = true;
        $data['is_admin'] = $role === 'super_admin';
        // A hospital_admin can only create staff for their own hospital; only a
        // Super Admin may seed another super_admin (hospital_id null).
        $data['hospital_id'] = $actor->isSuperAdmin() && $role === 'super_admin' ? null : $actor->hospital_id;

        if ($avatar !== null) {
            $data['avatar'] = $this->avatars->storeResized($avatar);
        }

        $user = User::create($data);

        return ['user' => $user, 'emailed' => $this->sendCredentials($user, $plain)];
    }

    /**
     * Update a staff account. The previous avatar file is deleted only once the
     * row has been saved, so a failed update never orphans the live image.
     *
     * @param  array<string, mixed>  $data  validated attributes
     */
    public function update(
        User $user,
        array $data,
        UploadedFile|TemporaryUploadedFile|null $avatar = null,
        bool $removeAvatar = false,
        ?string $newPassword = null,
    ): User {
        $data['is_admin'] = ($data['role'] ?? $user->role) === 'super_admin';

        if (filled($newPassword)) {
            // An admin-initiated reset — the affected user still sets their own
            // password at next login (constraint C14).
            $data['password'] = Hash::make($newPassword);
            $data['password_change_required'] = true;
        } else {
            unset($data['password']);
        }

        $previousAvatar = $user->avatar;
        $avatarChanged = false;

        if ($avatar !== null) {
            $data['avatar'] = $this->avatars->storeResized($avatar);
            $avatarChanged = true;
        } elseif ($removeAvatar && $previousAvatar) {
            $data['avatar'] = null;
            $avatarChanged = true;
        }

        $user->update($data);

        if ($avatarChanged && $previousAvatar && Storage::disk('public')->exists($previousAvatar)) {
            Storage::disk('public')->delete($previousAvatar);
        }

        return $user;
    }

    /** Queue the welcome email; returns false when the mailer refused it. */
    private function sendCredentials(User $user, string $plainPassword): bool
    {
        try {
            Mail::to($user->email)->queue(new WelcomeCredentials($user, $plainPassword));

            return true;
        } catch (\Throwable $e) {
            Log::error("Welcome email failed for {$user->email}: ".$e->getMessage());

            return false;
        }
    }
}
