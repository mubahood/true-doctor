<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use App\Models\Contracts\HoldsAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file attached to a piece of work — a result, a film, a signed consent.
 *
 * Three things order work in this system: a visit Order, a LabOrder and a
 * RadiologyOrder. The file store used to point at the first only, so a lab
 * result PDF and an X-ray film — the two documents a hospital most needs to
 * keep — had nowhere to go at all.
 *
 * It follows PatientDocument exactly: the file lives on the PRIVATE disk and
 * is only ever streamed back through OrderAttachmentController after a policy
 * check. A lab result is PHI; it gets no public URL and no guessable path.
 */
class OrderAttachment extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $table = 'attachments';

    protected $fillable = [
        'uuid', 'attachable_type', 'attachable_id', 'file_path', 'original_name', 'mime', 'size', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** The work this file belongs to. @return \Illuminate\Database\Eloquent\Relations\MorphTo */
    public function attachable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    /** True when this file belongs to $owner — the check every download makes. */
    public function belongsToWork(HoldsAttachments $owner): bool
    {
        return $this->attachable_type === $owner::class && (int) $this->attachable_id === (int) $owner->getKey();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Whether this is something the browser can show inline. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** A size a human reads, not a byte count. */
    public function readableSize(): string
    {
        $bytes = (int) $this->size;
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
