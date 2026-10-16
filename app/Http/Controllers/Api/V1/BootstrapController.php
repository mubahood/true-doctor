<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sync\PullService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/bootstrap?since=<revision>&have=wards,beds
 *
 * One call for an app to start from: the `meta` document, `capabilities`, the
 * reference catalogues this user may see, and what has been removed.
 *
 * - Without `since` (a first launch, or after a purge): every visible section in full.
 * - With `since` (a `revision` from an earlier answer): only the sections whose
 *   revision moved after it, each in full, plus tombstones after it. When
 *   nothing moved `unchanged` is true and `reference` is empty; the whole answer
 *   is also tagged with an ETag, so `If-None-Match` earns a 304.
 * - `have` names the sections the app holds; one it has never had is sent
 *   whatever its revision (a user who just gained a permission).
 * - `omitted_kinds` are sections this user may not see now: purge them.
 *
 * Reads the same sources as `sync/reference`, so the two cannot disagree.
 */
class BootstrapController extends Controller
{
    /** Tombstones returned at most; more means the app is too far behind and must rebuild. */
    private const REMOVED_CAP = 500;

    public function __invoke(Request $request, MetaController $meta, PullService $pull): JsonResponse
    {
        $user = $request->user();
        $since = max(0, (int) $request->query('since', '0'));
        $have = $request->filled('have')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $request->query('have')))))
            : null;

        // `revision` is read inside the report BEFORE the rows are: a change
        // landing in between is simply sent again next time, never lost.
        $report = $pull->referenceReport($user, $since, $have);
        $revision = $report['revision'];

        $removed = [];
        $rebuild = false;
        if ($since > 0) {
            $found = $pull->tombstonesAfter($since, $user, self::REMOVED_CAP + 1);
            $rebuild = $found->count() > self::REMOVED_CAP;
            $removed = $found->take(self::REMOVED_CAP)->map->toRemoval()->values()->all();
        }

        $document = $meta->document($request);

        return ApiResponse::success([
            'revision' => $revision,
            'unchanged' => $since > 0 && $report['unchanged'] && $removed === [] && ! $rebuild,
            'rebuild' => $rebuild,
            'meta' => $document,
            'capabilities' => $document['capabilities'],
            'reference' => $report['reference'] ?: new \stdClass,
            'revisions' => $report['revisions'] ?: new \stdClass,
            'omitted_kinds' => $report['omitted_kinds'],
            'removed' => $removed,
            'removed_reference' => $report['removed'] ?: new \stdClass,
        ]);
    }
}
