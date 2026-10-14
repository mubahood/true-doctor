<?php

namespace App\Http\Controllers;

use App\Models\StandaloneLicence;
use App\Models\StandaloneRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The SaaS side of standalone updates: which release a licence may install
 * next, and the file itself (docs/proposals/standalone-edition.md §9).
 *
 * The licence id is the credential — a random UUID that only the buyer's
 * licence file and the buyer's portal carry. The answer is the newest
 * published release built inside the licence's update entitlement; the
 * download is a signed address that lasts half an hour. A revoked or unknown
 * licence gets the same empty answer as "nothing new", so the endpoint says
 * nothing about which licences exist.
 */
class StandaloneUpdateFeedController extends Controller
{
    public function latest(Request $request): JsonResponse
    {
        $id = (string) $request->query('licence', '');
        $licence = preg_match('/^[0-9a-f-]{36}$/i', $id) ? StandaloneLicence::whereKey($id)->where('status', 'active')->first() : null;

        $release = $licence === null ? null : StandaloneRelease::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $licence->updates_until->copy()->endOfDay())
            ->orderByDesc('published_at')->orderByDesc('id')
            ->first();

        return response()->json([
            'latest' => $release === null ? null : [
                'version' => $release->version,
                'notes' => $release->notes,
                'published_at' => $release->published_at?->toIso8601String(),
                'size' => is_string($release->archive_path) && is_file($release->archive_path) ? filesize($release->archive_path) : null,
                'sha256' => $release->sha256,
                'download_url' => URL::temporarySignedRoute('standalone.updates.download', now()->addMinutes(30), [
                    'release' => $release->id, 'licence' => $licence->id,
                ]),
            ],
            'updates_until' => $licence?->updates_until->toDateString(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function download(Request $request, StandaloneRelease $release): BinaryFileResponse
    {
        $licence = StandaloneLicence::whereKey((string) $request->query('licence'))->where('status', 'active')->first();

        abort_if($licence === null || $release->status !== 'published' || $release->published_at === null
            || $release->published_at->greaterThan($licence->updates_until->copy()->endOfDay()), 403);
        abort_if(! is_string($release->archive_path) || ! is_file($release->archive_path)
            || ! hash_equals((string) $release->sha256, (string) hash_file('sha256', $release->archive_path)), 503);

        DB::table('standalone_downloads')->insert([
            'licence_id' => $licence->id, 'release_id' => $release->id,
            'ip' => $request->ip(), 'created_at' => now(),
        ]);

        return response()->download($release->archive_path, 'true-doctor-standalone-'.$release->version.'.zip', [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
