<?php

namespace App\Observers;

use App\Services\Audit\AuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Hands every write on an audited model to AuditTrail (docs/audit.md).
 * Registered for each model in AuditTrail::MODELS by AppServiceProvider.
 */
class AuditObserver
{
    public function __construct(private AuditTrail $trail) {}

    public function created(Model $model): void
    {
        $this->trail->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->trail->record($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->trail->record($model, 'deleted');
    }

    // No `restored`: restore() saves, and that save is already recorded as
    // `deleted_at` going back to empty.
}
