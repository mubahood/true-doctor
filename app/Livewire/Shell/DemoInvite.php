<?php

namespace App\Livewire\Shell;

use App\Support\Demo;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * "Create your own hospital" — the invitation a visitor exploring the
 * demonstration sees, and the dialog behind it.
 *
 * It renders nothing at all for a real hospital. That is the whole contract:
 * a paying customer must never be invited to sign up for the thing they are
 * already paying for, so the check is on the tenant and it is made on every
 * render rather than cached anywhere.
 *
 * The dialog does not push. Somebody halfway through looking at the ward
 * board has not finished, and a modal that treats "not yet" as a lesser
 * answer is a modal people learn to dismiss without reading. So the second
 * button is a real one, and the first thing the dialog says is what happens
 * to what they have been doing.
 */
class DemoInvite extends Component
{
    public bool $show = false;

    /** Only ever true inside the demonstration tenant. */
    public function isDemo(): bool
    {
        return Demo::isDemoUser(Auth::user());
    }

    #[\Livewire\Attributes\On('open-demo-invite')]
    public function open(): void
    {
        // Re-checked here as well as in the view: a Livewire action is a
        // request of its own and can be called by anything that can reach
        // the component.
        if ($this->isDemo()) {
            $this->show = true;
        }
    }

    public function render()
    {
        return view('livewire.shell.demo-invite', [
            'demo' => $this->isDemo(),
            'resets' => Demo::resets(),
            'resetsAt' => Demo::resetsAt(),
        ]);
    }
}
