<?php

namespace App\Livewire\Concerns;

use App\Support\CurrentHospital;
use Illuminate\Support\Facades\Auth;

/**
 * The finance pages' gate (docs/finance.md): the permission, and a hospital to
 * keep the books of. A super-admin who has not switched into a hospital has
 * every permission and no books, so the page is not there rather than broken.
 */
trait KeepsBooks
{
    protected function authorizeBooks(string $ability = 'finance.view'): void
    {
        abort_unless(Auth::user()?->can($ability), 403);
        abort_if(app(CurrentHospital::class)->id() === null, 404);
    }
}
