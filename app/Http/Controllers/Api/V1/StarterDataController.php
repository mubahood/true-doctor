<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Practice\Practice;
use App\Support\StarterData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Starter data (the catalogue pages' "Import starter data" modals), over the
 * API. Listing needs access-admin; importing needs what the web page needs to
 * create that record (its Policy), and calls the page's own persistSample().
 */
class StarterDataController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('access-admin'), 403);

        $practice = Practice::current();

        return ApiResponse::success([
            'practice' => ['chosen' => $practice->chosen, 'sector' => $practice->sector->key],
            'kinds' => collect(StarterData::availableFor($practice))
                ->map(fn (string $kind) => $this->describe($request, $kind))
                ->values(),
        ]);
    }

    public function import(Request $request, string $kind): JsonResponse
    {
        $this->resolve($kind);
        $meta = StarterData::KINDS[$kind];

        $this->authorize('create', $meta['model']);

        $data = $request->validate([
            'names' => ['nullable', 'array', 'max:500'],
            'names.*' => ['string', 'max:191'],
        ]);

        $imported = StarterData::import($kind, $data['names'] ?? null);

        return ApiResponse::success(
            ['imported' => $imported] + $this->describe($request, $kind),
            $imported > 0 ? "{$imported} ".$meta['noun'].($imported === 1 ? '' : 's').' imported.' : 'Nothing left to import.',
        );
    }

    /** 404 for a kind that does not exist, or that this practice does not use. */
    private function resolve(string $kind): void
    {
        abort_unless(
            isset(StarterData::KINDS[$kind]) && in_array($kind, StarterData::availableFor(Practice::current()), true),
            404,
            'No starter data of that kind for this practice.',
        );
    }

    /** @return array<string,mixed> */
    private function describe(Request $request, string $kind): array
    {
        $meta = StarterData::KINDS[$kind];
        $total = count(StarterData::rows($kind));
        $remaining = count(StarterData::remaining($kind));

        return [
            'kind' => $kind,
            'label' => $meta['label'],
            'module' => $meta['module'],
            'total' => $total,
            'imported' => $total - $remaining,
            'remaining' => $remaining,
            'already_imported' => $total > 0 && $remaining === 0,
            'existing' => $meta['model']::query()->count(),
            'can_import' => $request->user()->can('create', $meta['model']),
            'app_route' => $meta['route'],
        ];
    }
}
