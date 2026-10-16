<?php

namespace App\Events;

use App\Models\LabOrder;
use Illuminate\Foundation\Events\Dispatchable;

/** A lab order was completed: its results are out. Heard by App\Listeners\NotifyUser. */
class LabResultReleased
{
    use Dispatchable;

    public function __construct(public readonly LabOrder $order) {}
}
