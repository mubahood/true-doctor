<?php

namespace App\Models;

use App\Enums\SharedDocumentType;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A short link a patient opens without an account, to one document
 * (App\Services\PatientLinks\PatientLinkService). Only hashes of the token in
 * the URL and of the unlock code are stored.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int $patient_id
 * @property SharedDocumentType $document_type
 * @property int $document_id
 * @property string $title
 * @property string|null $reference
 * @property string $token_hash
 * @property string $code_hash
 * @property int $failed_attempts
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property int|null $created_by
 * @property int|null $patient_message_id
 * @property int $opens_count
 * @property \Illuminate\Support\Carbon|null $last_opened_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PatientLink extends Model
{
    use BelongsToHospital;

    /** Wrong codes before the link locks. */
    public const MAX_ATTEMPTS = 5;

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const LOCKED = 'locked';

    public const STATES = [self::ACTIVE, self::EXPIRED, self::REVOKED, self::LOCKED];

    protected $fillable = [
        'uuid', 'patient_id', 'document_type', 'document_id', 'title', 'reference', 'token_hash', 'code_hash',
        'failed_attempts', 'locked_at', 'expires_at', 'revoked_at', 'revoked_by', 'created_by', 'patient_message_id',
        'opens_count', 'last_opened_at',
    ];

    protected $hidden = ['token_hash', 'code_hash'];

    protected function casts(): array
    {
        return [
            'document_type' => SharedDocumentType::class,
            'failed_attempts' => 'integer',
            'opens_count' => 'integer',
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_opened_at' => 'datetime',
        ];
    }

    /** Withdrawn first, then locked, then expired: the reason a patient is told. */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => self::REVOKED,
            $this->locked_at !== null => self::LOCKED,
            $this->expires_at->isPast() => self::EXPIRED,
            default => self::ACTIVE,
        };
    }

    public function isActive(): bool
    {
        return $this->state() === self::ACTIVE;
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            self::ACTIVE => 'Active',
            self::EXPIRED => 'Expired',
            self::REVOKED => 'Withdrawn',
            self::LOCKED => 'Locked',
            default => ucfirst($state),
        };
    }

    public static function stateTone(string $state): string
    {
        return match ($state) {
            self::ACTIVE => 'success',
            self::LOCKED => 'danger',
            self::REVOKED => 'warn',
            default => 'neutral',
        };
    }

    /** @param  Builder<PatientLink>  $query */
    public function scopeInState(Builder $query, string $state): void
    {
        match ($state) {
            self::REVOKED => $query->whereNotNull('revoked_at'),
            self::LOCKED => $query->whereNull('revoked_at')->whereNotNull('locked_at'),
            self::EXPIRED => $query->whereNull('revoked_at')->whereNull('locked_at')->where('expires_at', '<=', now()),
            self::ACTIVE => $query->whereNull('revoked_at')->whereNull('locked_at')->where('expires_at', '>', now()),
            default => null,
        };
    }

    /** @param  Builder<PatientLink>  $query */
    public function scopeMatching(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $query->where(fn (Builder $q) => $q->where('reference', 'like', $like)
            ->orWhere('title', 'like', $like)
            ->orWhereHas('patient', fn (Builder $p) => $p->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('patient_no', 'like', $like)));
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @return BelongsTo<PatientMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class, 'patient_message_id');
    }

    /** @return HasMany<PatientLinkOpen, $this> */
    public function opens(): HasMany
    {
        return $this->hasMany(PatientLinkOpen::class)->latest('id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
