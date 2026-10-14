<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\StandaloneRelease;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** A built release, for a super admin to inspect or hand over by hand. */
class StandaloneReleaseDownloadController extends Controller
{
    public function __invoke(StandaloneRelease $release): BinaryFileResponse
    {
        abort_if(! is_string($release->archive_path) || ! is_file($release->archive_path), 404);

        return response()->download($release->archive_path, 'true-doctor-standalone-'.$release->version.'.zip', ['Cache-Control' => 'no-store, private']);
    }
}
