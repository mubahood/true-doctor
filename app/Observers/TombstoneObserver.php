<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\SyncTombstone;
use App\Models\Visit;
use App\Support\CurrentHospital;
use App\Support\SyncRevision;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes a tombstone when a listed record is deleted, or when an order leaves
 * the worklist by being completed or cancelled.
 *
 * Infrastructure like {@see SyncRevisionObserver}: it carries no business rule,
 * only "tell the devices". It hooks `deleted` (which a force delete also fires)
 * and, for lab and radiology orders, `updated` on a status change.
 *
 * The known gap this closes: a closed lab order used to vanish from the
 * worklist with nothing telling the device, which kept the bench item for ever.
 * Now closing it writes `lab_orders` and one `lab_items` tombstone per test,
 * reason `closed`.
 *
 * A bulk query-builder delete fires no model events and so writes nothing; the
 * code that deletes in bulk is the code that must write the tombstones.
 */
class TombstoneObserver
{
    /** Model class => entity name used on the wire. */
    public const ENTITIES = [
        Patient::class => 'patients',
        Visit::class => 'visits',
        Invoice::class => 'invoices',
        StockItem::class => 'stock_items',
        Appointment::class => 'appointments',
        LabOrder::class => 'lab_orders',
        RadiologyOrder::class => 'radiology_orders',
        Service::class => 'services',
    ];

    public function deleted(Model $model): void
    {
        $this->write($model, self::ENTITIES[$model::class] ?? null, 'deleted');

        if ($model instanceof LabOrder) {
            $this->writeLabItems($model, 'deleted');
        }
    }

    public function restored(Model $model): void
    {
        $this->clear($model, self::ENTITIES[$model::class] ?? null);

        if ($model instanceof LabOrder) {
            $this->clearLabItems($model);
        }
    }

    public function updated(Model $model): void
    {
        if (! $model->wasChanged('status')) {
            return;
        }

        if ($model instanceof LabOrder) {
            $closed = $model->status->isTerminal();
            $this->toggle($model, 'lab_orders', $closed);
            $closed ? $this->writeLabItems($model, 'closed') : $this->clearLabItems($model);
        } elseif ($model instanceof RadiologyOrder) {
            $closed = $model->status->isTerminal();
            $this->toggle($model, 'radiology_orders', $closed);
        }
    }

    private function toggle(Model $model, string $entity, bool $closed): void
    {
        $closed ? $this->write($model, $entity, 'closed') : $this->clear($model, $entity);
    }

    private function writeLabItems(LabOrder $order, string $reason): void
    {
        $hospitalId = $this->hospitalFor($order);

        if ($hospitalId === null) {
            return;
        }

        foreach ($order->items()->withoutGlobalScopes()->pluck('uuid') as $uuid) {
            if ($uuid !== null && $uuid !== '') {
                $this->insert($hospitalId, 'lab_items', (string) $uuid, $reason);
            }
        }
    }

    private function clearLabItems(LabOrder $order): void
    {
        $hospitalId = $this->hospitalFor($order);

        if ($hospitalId === null) {
            return;
        }

        $uuids = $order->items()->withoutGlobalScopes()->pluck('uuid')->filter()->map(fn ($u) => (string) $u)->all();

        if ($uuids !== []) {
            SyncTombstone::withoutGlobalScopes()->where('hospital_id', $hospitalId)
                ->where('entity', 'lab_items')->whereIn('uuid', $uuids)->delete();
        }
    }

    private function write(Model $model, ?string $entity, string $reason): void
    {
        $hospitalId = $this->hospitalFor($model);

        if ($entity === null || $hospitalId === null) {
            return;
        }

        $this->insert($hospitalId, $entity, $this->identity($model), $reason);
    }

    private function clear(Model $model, ?string $entity): void
    {
        $hospitalId = $this->hospitalFor($model);

        if ($entity === null || $hospitalId === null) {
            return;
        }

        SyncTombstone::withoutGlobalScopes()->where('hospital_id', $hospitalId)
            ->where('entity', $entity)->where('uuid', $this->identity($model))->delete();
    }

    private function insert(int $hospitalId, string $entity, string $uuid, string $reason): void
    {
        // One live tombstone per record: a second close or delete moves it
        // forward rather than piling up.
        SyncTombstone::withoutGlobalScopes()->where('hospital_id', $hospitalId)
            ->where('entity', $entity)->where('uuid', $uuid)->delete();

        SyncTombstone::withoutGlobalScopes()->create([
            'hospital_id' => $hospitalId,
            'entity' => $entity,
            'uuid' => $uuid,
            'revision' => SyncRevision::next($hospitalId),
            'reason' => $reason,
            'deleted_at' => now(),
        ]);
    }

    /** The uuid, or the numeric id as a string for a catalogue row that has none. */
    private function identity(Model $model): string
    {
        $uuid = $model->getAttribute('uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : (string) $model->getKey();
    }

    private function hospitalFor(Model $model): ?int
    {
        $onRow = $model->getAttribute('hospital_id');

        return $onRow !== null ? (int) $onRow : app(CurrentHospital::class)->id();
    }
}
