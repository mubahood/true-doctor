<?php

namespace App\Livewire\Settings;

use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * PLATFORM-GLOBAL site configuration (site name, tagline, contacts, verify
 * rate-limit) held in the hospital-agnostic `settings` table and shared by every
 * tenant and the public site.
 *
 * Only the SaaS operator (super-admin) may read or write it — a tenant
 * hospital_admin has `manage-settings` for their OWN hospital (Settings\Billing),
 * never for global config. Authorised in mount() AND render() (house rule 4).
 */
#[Layout('layouts.admin')]
class Site extends Component
{
    public string $site_name = '';

    public string $tagline = '';

    public ?string $contact_email = null;

    public ?string $contact_phone = null;

    public int $verify_rate_limit = 60;

    public function mount(): void
    {
        $this->authorizeManage();

        $settings = Settings::all();
        $this->site_name = (string) ($settings['site_name'] ?? '');
        $this->tagline = (string) ($settings['tagline'] ?? '');
        $this->contact_email = $settings['contact_email'] ?? null;
        $this->contact_phone = $settings['contact_phone'] ?? null;
        $this->verify_rate_limit = (int) ($settings['verify_rate_limit'] ?? 60);
    }

    private function authorizeManage(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
    }

    /** Same rules the retired SettingController::update validated. */
    protected function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:120'],
            'tagline' => ['required', 'string', 'max:191'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'verify_rate_limit' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function save(): void
    {
        $this->authorizeManage();

        foreach ($this->validate() as $key => $value) {
            Settings::set($key, $value);
        }

        $this->dispatch('toast', message: 'Settings saved.', type: 'success');
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.settings.site')->title('Site settings');
    }
}
