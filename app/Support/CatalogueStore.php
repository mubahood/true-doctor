<?php

namespace App\Support;

use App\Enums\OrderItemStatus;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\OrderItem;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyStudy;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How a catalogue row (price list, lab test, radiology study, stock item or
 * category) is saved, shared by the web's slide-over (CrudModal) and the API.
 *
 * Catalogue tables carry a unique key on (hospital_id, name) that ignores
 * soft-deletes, so creating an archived name again restores the archived row
 * and updates it instead of hitting the index.
 */
class CatalogueStore
{
    /** How far back "lately" reaches when counting what a catalogue line earns. */
    public const USAGE_DAYS = 90;

    /**
     * @param  class-string<Model>  $class
     * @param  array<string,mixed>  $data  validated
     * @param  list<string>  $nullable  fields where '' persists as null
     * @param  list<string>  $uniqueKey  columns that identify "the same record" when archived
     * @return array{0:Model,1:string} the row, and 'created' | 'updated' | 'restored'
     */
    public static function persist(string $class, ?Model $model, array $data, array $nullable = [], array $uniqueKey = ['name']): array
    {
        foreach ($nullable as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field]) && trim($data[$field]) === '') {
                $data[$field] = null;
            }
        }

        if ($model !== null) {
            $model->update($data);

            return [$model, 'updated'];
        }

        if ($archived = self::archivedTwin($class, $data, $uniqueKey)) {
            // archivedTwin only ever returns a soft-deleting model.
            if (method_exists($archived, 'restore')) {
                $archived->restore();
            }
            $archived->update($data);

            return [$archived, 'restored'];
        }

        return [$class::create($data), 'created'];
    }

    /** @param  class-string<Model>  $class */
    private static function archivedTwin(string $class, array $data, array $uniqueKey): ?Model
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            return null;
        }

        $query = $class::query()
            ->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->whereNotNull('deleted_at');
        foreach ($uniqueKey as $column) {
            if (! array_key_exists($column, $data) || $data[$column] === null || $data[$column] === '') {
                return null;
            }
            $query->where($column, $data[$column]);
        }

        return $query->first();
    }

    /**
     * Whether anybody is actually ordering a catalogue line: how often in the
     * last quarter, and what that came to.
     *
     * @return array{times:int,earned:string,days:int}
     */
    public static function usage(Model $line): array
    {
        $since = now()->subDays(self::USAGE_DAYS);

        // Lab and radiology are counted on their own order items, not
        // OrderItem: they are ordered through their own order.
        [$rows, $column] = match (true) {
            $line instanceof Service => [
                OrderItem::where('service_id', $line->getKey())->where('created_at', '>=', $since)
                    ->where('status', '!=', OrderItemStatus::Cancelled)->get(['quantity', 'line_total']), 'line_total',
            ],
            $line instanceof LabTest => [
                LabOrderItem::where('lab_test_id', $line->getKey())->where('created_at', '>=', $since)->get(['price']), 'price',
            ],
            $line instanceof RadiologyStudy => [
                RadiologyOrderItem::where('radiology_study_id', $line->getKey())->where('created_at', '>=', $since)->get(['price']), 'price',
            ],
            default => [collect(), 'price'],
        };

        return [
            'times' => $rows->count(),
            'earned' => $rows->reduce(fn (string $sum, Model $l) => bcadd($sum, (string) $l->getAttribute($column), 2), '0.00'),
            'days' => self::USAGE_DAYS,
        ];
    }
}
