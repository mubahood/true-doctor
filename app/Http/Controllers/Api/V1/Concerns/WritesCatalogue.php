<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use App\Support\CatalogueStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Create / update / delete for the catalogue pages (price list, lab tests,
 * radiology studies, stock categories, stock items), exactly as the web's
 * slide-over does: the Policy decides, the FormRequest's rulesFor() validates,
 * CatalogueStore saves (restoring an archived twin of the same name) and
 * deleting archives, with the model's own refusal where it has one.
 *
 * An update may send only what changed: the rest is filled from the row, so a
 * client toggling is_active cannot blank anything else - and, unlike the
 * FormRequest's prepareForValidation, an absent is_active does not deactivate.
 */
trait WritesCatalogue
{
    /**
     * @param  class-string<Model>  $class
     * @param  list<string>  $fields  the form's fields
     * @param  list<string>  $nullable
     * @param  array<string,mixed>  $defaults  for a create, where a field is left out
     * @param  array<string,mixed>  $extra  set on a brand-new row only (a uuid, say)
     * @return array{0:Model,1:string}
     */
    protected function createRow(Request $request, string $class, callable $rulesFor, array $fields, array $nullable, array $defaults = [], array $extra = []): array
    {
        $this->authorize('create', $class);

        $input = $request->only($fields) + $defaults;
        $data = Validator::make($input, $rulesFor(null))->validate();

        [$model, $state] = CatalogueStore::persist($class, null, $data + ($extra === [] ? [] : $extra), $nullable);

        return [$model->fresh() ?? $model, $state];
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $nullable
     * @return array{0:Model,1:string}
     */
    protected function updateRow(Request $request, Model $model, callable $rulesFor, array $fields, array $nullable): array
    {
        $this->authorize('update', $model);

        $current = [];
        foreach ($fields as $f) {
            $v = $model->{$f};
            $current[$f] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
        }

        $data = Validator::make($request->only($fields) + $current, $rulesFor($model->getKey()))->validate();

        // Only what was asked to change is written.
        $data = array_intersect_key($data, $request->only($fields));

        return CatalogueStore::persist($model::class, $model, $data, $nullable);
    }

    /** Delete (archive) a row, unless the model refuses. */
    protected function destroyRow(Model $model, string $noun): JsonResponse
    {
        $this->authorize('delete', $model);

        if (method_exists($model, 'deletionBlocker') && ($why = $model->deletionBlocker())) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $why, 422);
        }

        $model->delete();

        return ApiResponse::success(null, $noun.' archived.');
    }

    protected function perPage(Request $request, int $default = 20): int
    {
        return min(max((int) $request->query('per_page', $default), 1), 100);
    }

    protected function saved(string $noun, string $state, mixed $resource): JsonResponse
    {
        return ApiResponse::success(
            $resource,
            $noun.match ($state) {
                'created' => ' created.', 'restored' => ' restored from the archive.', default => ' updated.'
            },
            $state === 'updated' ? 200 : 201,
        );
    }
}
