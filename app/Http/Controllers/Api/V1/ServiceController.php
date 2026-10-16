<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PagesAndDeltas;
use App\Http\Controllers\Api\V1\Concerns\WritesCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\LedgerAccount;
use App\Models\Service;
use App\Services\Finance\ChartOfAccounts;
use App\Support\ApiResponse;
use App\Support\CatalogueStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The price list (web: Price list, admin.services.index). Read with
 * access-admin, written with services.manage - the ServicePolicy the page uses.
 * A line is a fixed price or, with max_price above price, a range the counter
 * picks from when charging (Service::chargePrice).
 */
class ServiceController extends Controller
{
    use PagesAndDeltas, WritesCatalogue {
        PagesAndDeltas::perPage insteadof WritesCatalogue;
    }

    private const FIELDS = ['name', 'code', 'category', 'ledger_account_id', 'price', 'max_price', 'tax_exempt', 'is_active'];

    private const NULLABLE = ['code', 'category', 'ledger_account_id', 'max_price'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Service::class);

        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $rows = $this->applyDelta(Service::query()
            ->when($q !== '', fn ($x) => $x->where(fn ($y) => $y->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->orderBy('name'), $request)
            ->paginate($this->perPage($request));

        return $this->deltaPage($request, $rows, ServiceResource::class, $this->formMeta());
    }

    public function show(Service $service): JsonResponse
    {
        $this->authorize('viewAny', Service::class);

        return ApiResponse::success((new ServiceResource($service->load('incomeAccount')))->resolve() + ['usage' => CatalogueStore::usage($service)]);
    }

    public function store(Request $request): JsonResponse
    {
        [$service, $state] = $this->createRow($request, Service::class, fn ($id) => ServiceRequest::rulesFor($id), self::FIELDS, self::NULLABLE, ['is_active' => true, 'tax_exempt' => false]);

        return $this->saved('Service', $state, new ServiceResource($service));
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        [$service, $state] = $this->updateRow($request, $service, fn ($id) => ServiceRequest::rulesFor($id), self::FIELDS, self::NULLABLE);

        return $this->saved('Service', $state, new ServiceResource($service));
    }

    public function destroy(Service $service): JsonResponse
    {
        return $this->destroyRow($service, 'Service');
    }

    /** What the form offers: the income accounts, and the specialties in use. @return array<string,mixed> */
    private function formMeta(): array
    {
        app(ChartOfAccounts::class)->ensure();

        return [
            'income_accounts' => LedgerAccount::query()->active()->where('type', 'income')
                ->where(fn ($q) => $q->whereNull('subtype')->orWhere('subtype', '!=', 'contra_income'))
                ->ordered()->get()->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values(),
            'categories' => Service::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values(),
        ];
    }
}
