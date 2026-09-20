<?php

namespace App\Livewire\Onboarding\Steps;

use App\Exceptions\PlanLimitExceededException;
use App\Models\User;
use App\Services\StaffService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Setup step: invite the first colleague. Accounts are created through
 * StaffService, exactly as the staff module does — a temporary password is
 * generated, the welcome email is queued, and the plan's seat limit applies.
 */
class Staff extends Component
{
    use InteractsWithSetup;

    public string $name = '';

    public string $email = '';

    public ?string $role = null;

    public ?string $phone = null;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(User::assignableRolesFor(Auth::user()))],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array<string,string> role => label */
    #[Computed]
    public function roles(): array
    {
        $out = [];
        foreach (User::assignableRolesFor(Auth::user()) as $role) {
            $out[$role] = (new User(['role' => $role]))->role_label;
        }

        return $out;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    #[Computed]
    public function existing()
    {
        return User::currentHospital()
            ->where('id', '!=', Auth::id())
            ->where('is_active', true)
            ->orderBy('name')
            ->take(12)
            ->get(['id', 'name', 'role']);
    }

    public function invite(StaffService $staff): void
    {
        $this->authorizeSetup();
        $data = $this->validate();
        $data['is_active'] = true;

        try {
            $result = $staff->create(Auth::user(), $data);
        } catch (PlanLimitExceededException $e) {
            $this->addError('email', $e->getMessage());

            return;
        }

        $this->reset(['name', 'email', 'role', 'phone']);
        unset($this->existing);

        $this->stepCompleted($result['emailed']
            ? $data['name'].' invited — their sign-in details have been emailed.'
            : $data['name'].' added. The welcome email could not be sent; share their password from the staff page.');
    }

    public function render()
    {
        $this->authorizeSetup();

        return view('livewire.onboarding.steps.staff');
    }
}
