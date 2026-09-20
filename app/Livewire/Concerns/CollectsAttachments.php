<?php

namespace App\Livewire\Concerns;

use App\Models\Contracts\HoldsAttachments;
use App\Models\OrderAttachment;
use App\Services\OrderAttachmentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Files filed against a piece of work, from whatever screen is showing it.
 *
 * A typed result is half the record. The other half is what the machine
 * printed — the analyser's report, the film, the scanned request — and the lab
 * and radiology worklists could take the first and not the second, because the
 * file store pointed at visit orders and nothing else.
 *
 * The host says WHAT the files belong to (`attachmentOwner`) and WHO may add
 * them (`assertMayAttach`). Everything else — the private disk, the size and
 * type rules, the tenant scope — is OrderAttachmentService, unchanged.
 */
trait CollectsAttachments
{
    use WithFileUploads;

    /** @var list<TemporaryUploadedFile> */
    public array $files = [];

    /** How big one file may be, in kilobytes. A CT report is not small. */
    public const MAX_FILE_KB = 10240;

    /** The work the files belong to, or null when nothing is open. */
    abstract protected function attachmentOwner(): ?HoldsAttachments;

    /** Throws if the current user may not file anything against it. */
    abstract protected function assertMayAttach(): void;

    /**
     * Livewire hands the files over as soon as they are chosen.
     *
     * Stored one at a time and reported one at a time: a batch that half
     * worked should say which half, not fail silently as a whole.
     */
    public function updatedFiles(OrderAttachmentService $attachments): void
    {
        $this->assertMayAttach();

        $owner = $this->attachmentOwner();

        if ($owner === null) {
            $this->files = [];

            return;
        }

        $this->validate([
            'files.*' => ['file', 'max:'.self::MAX_FILE_KB, 'mimes:pdf,png,jpg,jpeg,gif,webp,txt,csv,doc,docx'],
        ], [
            'files.*.max' => 'Each file has to be under '.(int) (self::MAX_FILE_KB / 1024).' MB.',
            'files.*.mimes' => 'Attach a PDF, an image, a text file or a document.',
        ]);

        $kept = 0;

        foreach ($this->files as $file) {
            $attachments->store($owner, $file, Auth::id());
            $kept++;
        }

        $this->files = [];
        $this->afterAttaching();

        if ($kept > 0) {
            $this->dispatch('toast', type: 'success',
                message: $kept.' '.\Illuminate\Support\Str::plural('file', $kept).' attached.');
        }
    }

    public function removeAttachment(int $attachmentId, OrderAttachmentService $attachments): void
    {
        $this->assertMayAttach();

        $owner = $this->attachmentOwner();

        if ($owner === null) {
            return;
        }

        /** @var OrderAttachment|null $attachment */
        $attachment = $owner->attachments()->whereKey($attachmentId)->first();

        // Scoped through the owner, so an id from another record is a no-op
        // rather than somebody else's result being deleted.
        if ($attachment === null) {
            return;
        }

        $attachments->delete($attachment);
        $this->afterAttaching();

        $this->dispatch('toast', type: 'success', message: 'File removed.');
    }

    /** Whatever the host caches about the record it is showing. */
    protected function afterAttaching(): void
    {
        //
    }
}
