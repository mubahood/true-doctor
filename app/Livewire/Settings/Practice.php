<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings → Your practice. The same form as the first setup step
 * (App\Livewire\Onboarding\Steps\Practice), on a page of its own, for a
 * hospital that has grown — added a dental chair, opened a ward — or chose
 * wrongly the first time. Changing it changes what is suggested and what the
 * menu shows; it never removes anything.
 */
#[Layout('layouts.admin')]
class Practice extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
    }

    public function render()
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);

        return view('livewire.settings.practice')->title('Your practice');
    }
}
