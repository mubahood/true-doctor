<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class AvatarService
{
    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::gd();
    }

    /**
     * A hospital's own mark, for the top of everything it prints.
     *
     * Not `storeResized`: a logo is not a face. Squaring one crops the wording
     * off half the logos in the world, so this fits it INSIDE a box and keeps
     * its shape. PNG, because a logo with a transparent background must stay
     * transparent on a white sheet, and JPEG cannot.
     *
     * Returns the stored relative path on the public disk.
     */
    public function storeLogo(UploadedFile $file, int $maxWidth = 600, int $maxHeight = 240): string
    {
        $filename = 'logos/'.Str::uuid()->toString().'.png';

        try {
            $encoded = $this->manager->read($file->getRealPath())
                ->scaleDown($maxWidth, $maxHeight)
                ->toPng();

            Storage::disk('public')->put($filename, (string) $encoded);

            return $filename;
        } catch (\Throwable) {
            // A logo that cannot be processed is still better on the page than
            // no logo at all, so it is stored as it arrived.
            return $file->store('logos', 'public');
        }
    }

    /**
     * Resize an uploaded image to a square `$size`×`$size` JPEG and store it on
     * the public disk. Returns the stored relative path (e.g. "avatars/abc.jpg").
     *
     * Falls back to a plain store if image processing fails for any reason.
     */
    public function storeResized(UploadedFile $file, string $dir = 'avatars', int $size = 200): string
    {
        $filename = $dir.'/'.Str::uuid()->toString().'.jpg';

        try {
            $encoded = $this->manager->read($file->getRealPath())
                ->cover($size, $size)
                ->toJpeg(85);

            Storage::disk('public')->put($filename, (string) $encoded);

            return $filename;
        } catch (\Throwable $e) {
            // Processing failed (corrupt file, unsupported format) — store as-is.
            return $file->store($dir, 'public');
        }
    }
}
