<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string|null $username
 * @property string $email
 * @property string $password
 * @property string|null $role
 * @property int|null $hospital_id
 * @property bool $is_active
 * @property bool $is_admin
 * @property bool $password_change_required
 */
class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, HasFactory, HasRoles, MustVerifyEmail, Notifiable;

    protected $fillable = [
        'name', 'username', 'email', 'password', 'role', 'hospital_id',
        'phone', 'bio', 'avatar', 'is_active', 'is_admin', 'last_active_at',
        'password_change_required',
    ];

    /**
     * Scope to the current tenant's users only. User has no BelongsToHospital
     * global scope (super-admins live in the same table), so any staff dropdown
     * MUST scope explicitly or it leaks users across hospitals.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeCurrentHospital(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('hospital_id', app(\App\Support\CurrentHospital::class)->id());
    }

    /**
     * Scope to the accounts $actor is allowed to see and manage: everything for
     * a structural super-admin, their own hospital for anybody else. The single
     * tenancy gate for staff management (see App\Services\StaffService).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeManageableBy(\Illuminate\Database\Eloquent\Builder $query, User $actor): \Illuminate\Database\Eloquent\Builder
    {
        return $actor->isSuperAdmin() ? $query : $query->where('hospital_id', $actor->hospital_id);
    }

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_admin' => 'boolean',
            'last_active_at' => 'datetime',
            'password_change_required' => 'boolean',
        ];
    }

    // ── Tenancy ────────────────────────────────────────────────

    /**
     * Null for a super-admin (SaaS central, not scoped to a hospital).
     *
     * @return BelongsTo<Hospital, $this>
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hospital_id === null && $this->role === 'super_admin';
    }

    /** Clinical/HR profile (Step 8) — one per staff user, same hospital. */
    public function staffProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    /**
     * The weekly availability windows this user sits, if they are a doctor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<DoctorSchedule, $this>
     */
    public function schedules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    public function isHospitalAdmin(): bool
    {
        return $this->role === 'hospital_admin';
    }

    // ── Avatar helper ──────────────────────────────────────────

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar && Storage::disk('public')->exists($this->avatar)) {
            return asset('storage/'.$this->avatar);
        }

        return null;
    }

    public function getInitialsAttribute(): string
    {
        $parts = explode(' ', trim($this->name ?? 'U'));
        $initials = strtoupper(substr($parts[0], 0, 1));
        if (count($parts) > 1) {
            $initials .= strtoupper(substr(end($parts), 0, 1));
        }

        return $initials;
    }

    // ── Password reset ─────────────────────────────────────────

    /**
     * Confirm this address — through the queue, like every other message.
     *
     * Overridden because Laravel's default sends inline, which put an SMTP
     * round trip inside a web request: a provider refusing a login turned
     * "resend the email" into a 500 and a stack trace.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    // ── Role helpers ───────────────────────────────────────────

    /** HMS staff roles (HMS_PLAN.md §5; mirrors Spatie role names & RbacSeeder). */
    public const STAFF_ROLES = [
        'super_admin', 'hospital_admin', 'doctor', 'nurse', 'receptionist',
        'pharmacist', 'lab_technician', 'radiologist', 'accountant', 'records_officer',
    ];

    /**
     * Roles $actor may assign to a staff account. Only a SaaS super-admin may
     * mint another super-admin; a hospital admin holding `manage-users` must
     * never be able to promote anyone (including themselves) to the
     * platform-wide role, whose policy bypass would then apply in-tenant.
     *
     * @return list<string>
     */
    public static function assignableRolesFor(?User $actor): array
    {
        $roles = self::STAFF_ROLES;

        if (! $actor || ! $actor->isSuperAdmin()) {
            $roles = array_values(array_diff($roles, ['super_admin']));
        }

        return $roles;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true) || $this->is_admin === true;
    }

    protected static function booted(): void
    {
        // Keep the Spatie role in sync with the `role` column so permission
        // checks work everywhere without a separate workflow.
        static::saved(function (User $user) {
            if ($user->wasChanged('role') || $user->wasRecentlyCreated) {
                $user->syncSpatieRole();
            }
        });
    }

    /** The canonical Spatie role name for this user's `role` column, or null if unset. */
    public function spatieRoleName(): ?string
    {
        return match ($this->role) {
            'admin' => 'super_admin',   // legacy alias
            default => $this->role,
        };
    }

    /** Mirror the role column into Spatie roles (no-op if unset or roles aren't seeded). */
    public function syncSpatieRole(): void
    {
        $name = $this->spatieRoleName();
        if ($name !== null && \Spatie\Permission\Models\Role::where('name', $name)->where('guard_name', 'web')->exists()) {
            $this->syncRoles([$name]);
        }
    }

    public function canAccessAdmin(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true) || $this->is_admin;
    }

    public function getRoleLabelAttribute(): string
    {
        return match ($this->role) {
            'admin', 'super_admin' => 'Super Admin',
            'hospital_admin' => 'Hospital Admin',
            'doctor' => 'Doctor',
            'nurse' => 'Nurse',
            'receptionist' => 'Receptionist',
            'pharmacist' => 'Pharmacist',
            'lab_technician' => 'Lab Technician',
            'radiologist' => 'Radiologist',
            'accountant' => 'Accountant',
            'records_officer' => 'Records Officer',
            default => ucfirst(str_replace('_', ' ', $this->role ?? 'Staff')),
        };
    }
}
