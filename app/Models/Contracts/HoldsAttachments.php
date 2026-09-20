<?php

namespace App\Models\Contracts;

use App\Models\OrderAttachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A piece of work that can carry files: a result, a film, a signed consent.
 *
 * Three things order work in this system — a visit Order, a LabOrder and a
 * RadiologyOrder — and until the file store was made polymorphic only the
 * first could hold anything. This names what they share, so a screen that
 * collects files (App\Livewire\Concerns\CollectsAttachments) can say what it
 * needs without naming all three.
 */
interface HoldsAttachments
{
    /** @return MorphMany<OrderAttachment, covariant \Illuminate\Database\Eloquent\Model> */
    public function attachments(): MorphMany;

    /** The model's own key, for the folder a file is stored under. */
    public function getKey();
}
