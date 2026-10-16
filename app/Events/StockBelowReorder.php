<?php

namespace App\Events;

use App\Models\StockItem;
use Illuminate\Foundation\Events\Dispatchable;

/** A movement took an item from above its reorder level to at or below it. Heard by App\Listeners\NotifyUser. */
class StockBelowReorder
{
    use Dispatchable;

    public function __construct(public readonly StockItem $item) {}
}
