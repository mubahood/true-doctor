<?php

namespace App\Livewire\Shell;

use App\Services\AvatarService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Self-service "My profile" and "Change password" modals for the signed-in
 * staff member. Replaces the fetch()+location.reload() drawer and the
 * admin/api/profile/* JSON endpoints; everything happens in place.
 */
class ProfileModal extends Component
{
    use WithFileUploads;

    public bool $showProfile = false;

    public bool $showPassword = false;

    public string $name = '';

    public ?string $phone = null;

    public ?string $bio = null;

    public $avatar = null;

    public bool $remove_avatar = false;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[On('open-profile')]
    public function openProfile(): void
    {
        $u = Auth::user();
        $this->name = (string) $u->name;
        $this->phone = $u->phone;
        $this->bio = $u->bio;
        $this->avatar = null;
        $this->remove_avatar = false;
        $this->resetErrorBag();
        $this->showProfile = true;
    }

    #[On('open-password')]
    public function openPassword(): void
    {
        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->resetErrorBag();
        $this->showPassword = true;
    }

    public function saveProfile(AvatarService $avatars): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = Auth::user();
        $old = $user->avatar;
        $payload = ['name' => $data['name'], 'phone' => $data['phone'] ?: null, 'bio' => $data['bio'] ?: null];

        if ($this->avatar) {
            $payload['avatar'] = $avatars->storeResized($this->avatar);
        } elseif ($this->remove_avatar) {
            $payload['avatar'] = null;
        }

        $user->update($payload);

        // Delete the previous file only after the update succeeded.
        if ($old && ($this->avatar || $this->remove_avatar) && $old !== ($payload['avatar'] ?? null)) {
            Storage::disk('public')->delete($old);
        }

        $this->showProfile = false;
        $this->dispatch('toast', message: 'Profile updated.', type: 'success');
        $this->dispatch('profile-updated', name: $user->name);
    }

    public function savePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        Auth::user()->forceFill([
            'password' => Hash::make($this->password),
            'password_change_required' => false,
        ])->save();

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->showPassword = false;
        $this->dispatch('toast', message: 'Password updated.', type: 'success');
    }

    public function render()
    {
        return view('livewire.shell.profile-modal');
    }
}
