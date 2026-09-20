<?php

namespace App\Services;

use App\Models\Contracts\HoldsAttachments;
use App\Models\OrderAttachment;
use App\Support\CurrentHospital;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Files attached to an order's report — results, films, signed consent.
 *
 * Deliberately the same shape as DocumentService: the private `local` disk,
 * a per-hospital path, an opaque filename, and no public URL anywhere. A lab
 * result is PHI; it is streamed back only through OrderAttachmentController,
 * which runs the visit policy first (C12: PHI at rest).
 */
class OrderAttachmentService
{
    private const DISK = 'local';

    /**
     * File something against a piece of work.
     *
     * $owner is a visit Order, a LabOrder or a RadiologyOrder — one store for
     * all three, because a lab result PDF and an X-ray film are the same kind
     * of thing as an order's report and deserve the same private disk and the
     * same policy in front of them.
     */
    public function store(HoldsAttachments $owner, UploadedFile $file, ?int $uploadedBy = null): OrderAttachment
    {
        $hospitalId = app(CurrentHospital::class)->id();
        if ($hospitalId === null) {
            throw new RuntimeException('Cannot store an attachment with no resolved hospital.');
        }

        $extension = $file->getClientOriginalExtension();
        $name = Str::uuid().($extension !== '' ? '.'.$extension : '');
        // Foldered by KIND as well as id, so two kinds of order numbered 7
        // cannot land in the same directory.
        $folder = Str::of($owner::class)->afterLast('\\')->snake()->plural();
        $path = $file->storeAs("{$folder}/{$hospitalId}/{$owner->getKey()}", $name, self::DISK);

        return OrderAttachment::create([
            'uuid' => (string) Str::uuid(),
            'attachable_type' => $owner::class,
            'attachable_id' => $owner->getKey(),
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public function delete(OrderAttachment $attachment): void
    {
        if ($this->disk()->exists($attachment->file_path)) {
            $this->disk()->delete($attachment->file_path);
        }

        $attachment->delete();
    }
}
