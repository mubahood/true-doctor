<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property \Illuminate\Support\Carbon|null $published_at */
/**
 * A signed installation package, built from one commit of main (Super → Standalone releases).
 *
 * @property int $id
 * @property string $version
 * @property string|null $commit
 * @property string $status queued | building | staged | published | failed | yanked
 * @property string|null $notes
 * @property string|null $progress
 * @property string|null $failure
 * @property string|null $archive_path
 * @property string|null $sha256
 * @property int|null $file_count
 * @property \Illuminate\Support\Carbon|null $staged_at
 * @property \Illuminate\Support\Carbon|null $clean_install_passed_at
 * @property \Illuminate\Support\Carbon|null $published_at
 */
class StandaloneRelease extends Model
{
    protected $fillable = ['version', 'notes', 'status', 'progress', 'failure', 'commit', 'archive_path', 'sha256', 'file_count', 'staged_at', 'clean_install_passed_at', 'published_at'];

    protected function casts(): array
    {
        return ['staged_at' => 'datetime', 'clean_install_passed_at' => 'datetime', 'published_at' => 'datetime'];
    }
}
