<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Services\PracticeService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use App\Support\Practice\Pack;
use App\Support\Practice\Practice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Your practice (manage-settings): what kind of practice this is,
 * which specialties it adds and which modules it uses, and the one-click setup
 * from that template. Saving only records the answers; nothing that exists
 * changes. The same PracticeService the page and the setup wizard call.
 */
class PracticeSettingsController extends Controller
{
    use ReadsAdminInput;

    public function __construct(private readonly PracticeService $service) {}

    public function show(Request $request): JsonResponse
    {
        $hospital = $this->hospital($request);

        return ApiResponse::success($this->payload($hospital));
    }

    public function update(Request $request): JsonResponse
    {
        $hospital = $this->hospital($request);

        $data = $request->validate([
            'sector' => ['required', 'string', Rule::in(array_keys(Practice::sectors()))],
            'packs' => ['nullable', 'array'],
            'packs.*' => ['string', Rule::in(array_keys(Practice::PACKS))],
            // Absent or null: the modules follow the sector. A list: the admin's own choice.
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(array_keys(Practice::MODULES))],
        ]);

        try {
            $this->service->choose($hospital, $data['sector'], $data['packs'] ?? [], array_key_exists('modules', $data) ? $data['modules'] : null);
        } catch (\DomainException $e) {
            return $this->refused($e->getMessage(), 'sector');
        }

        return ApiResponse::success($this->payload($hospital->fresh()), 'Saved — '.Practice::sector($data['sector'])->label.'.');
    }

    /** One click: everything the saved template describes that the hospital does not have yet. */
    public function apply(Request $request): JsonResponse
    {
        $hospital = $this->hospital($request);
        $practice = Practice::of($hospital);

        if (! $practice->chosen) {
            return $this->refused('Choose the kind of practice first.', 'sector');
        }

        $data = $request->validate([
            'parts' => ['nullable', 'array'],
            'parts.*' => ['string', Rule::in(array_keys(PracticeService::PARTS))],
        ]);
        $parts = array_values(array_intersect($data['parts'] ?? array_keys($this->service->preview($practice)), array_keys(PracticeService::PARTS)));

        if ($parts === []) {
            return $this->refused('Tick at least one part to set up.', 'parts');
        }

        try {
            $created = $this->service->apply($practice, $parts, $request->user()->id);
        } catch (\DomainException|\App\Exceptions\ClosedPeriodException $e) {
            return $this->refused($e->getMessage(), 'parts');
        }

        $total = array_sum($created);

        return ApiResponse::success(
            ['created' => $created, 'total' => $total] + $this->payload($hospital->fresh()),
            $total > 0
                ? $total.' things set up — review the prices on the price list before you start billing.'
                : 'Everything in your template is already set up.',
        );
    }

    private function hospital(Request $request): Hospital
    {
        abort_unless($request->user()->can('manage-settings'), 403);

        $hospital = app(HospitalSettings::class)->hospital();
        abort_if($hospital === null, 404, 'No hospital in context.');

        return $hospital;
    }

    /** @return array<string,mixed> */
    private function payload(Hospital $hospital): array
    {
        $practice = Practice::of($hospital);

        return [
            'chosen' => $practice->chosen,
            'sector' => $practice->sector->key,
            'packs' => $practice->extraPacks,
            // null: the modules follow the sector; a list: the admin's own choice.
            'modules' => $practice->modules(),
            'modules_customised' => $practice->modules !== null,
            'addable_packs' => $practice->addablePacks(),
            'counts' => $practice->template()->counts(),
            // What applying would add, per part (empty until a practice is chosen).
            'preview' => $practice->chosen ? $this->service->preview($practice) : [],
            'catalogue' => [
                'sectors' => array_values(array_map(fn ($s) => [
                    'key' => $s->key, 'label' => $s->label, 'icon' => $s->icon, 'summary' => $s->summary,
                    'packs' => $s->packs, 'suggested' => $s->suggested, 'modules' => $s->modules,
                    'default_modules' => Practice::defaultModules($s),
                ], Practice::sectors())),
                'packs' => array_values(array_map(fn (string $key) => $this->pack($key), array_keys(Practice::PACKS))),
                'modules' => array_values(array_map(fn (string $key, array $m) => ['key' => $key] + $m, array_keys(Practice::MODULES), Practice::MODULES)),
                'parts' => PracticeService::PARTS,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function pack(string $key): array
    {
        /** @var class-string<Pack> $class */
        $class = Practice::PACKS[$key];

        return ['key' => $key, 'label' => $class::label(), 'icon' => $class::icon(), 'summary' => $class::summary()];
    }
}
