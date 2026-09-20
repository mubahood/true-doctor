<?php

namespace App\Livewire\Onboarding\Steps;

use App\Http\Requests\HospitalProfileRequest;
use App\Services\AvatarService;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Setup step: the hospital's own details. These print on every invoice,
 * receipt, lab report and ID card, so the system asks for them first.
 */
class Profile extends Component
{
    use InteractsWithSetup, WithFileUploads;

    public string $name = '';

    public string $address = '';

    public ?string $phone = null;

    public ?string $email = null;

    public string $timezone = 'Africa/Kampala';

    /**
     * The hospital's mark, asked for HERE.
     *
     * It lived only on the letterhead settings page, which a new hospital has
     * no reason to visit — so the first invoice a hospital ever sent went out
     * plain, and nothing had asked. It is not required: a hospital without a
     * logo is a hospital, and holding setup up for an image file would be
     * absurd.
     */
    public ?TemporaryUploadedFile $logo = null;

    /** What is already on file, so the step can show it. */
    public ?string $currentLogo = null;

    /** A short, regionally sensible list; the full tz database is overwhelming here. */
    public const TIMEZONES = [
        'Africa/Kampala', 'Africa/Nairobi', 'Africa/Dar_es_Salaam', 'Africa/Kigali',
        'Africa/Bujumbura', 'Africa/Juba', 'Africa/Addis_Ababa', 'Africa/Lagos',
        'Africa/Johannesburg', 'Africa/Cairo', 'UTC',
    ];

    public function mount(): void
    {
        $this->authorizeSetup();

        $hospital = $this->hospital();
        $contact = app(\App\Support\OnboardingStatus::class)->contact($hospital);

        $this->name = (string) $hospital->name;
        $this->address = (string) ($hospital->address ?? '');
        $this->phone = isset($contact['phone']) ? (string) $contact['phone'] : null;
        $this->email = isset($contact['email']) ? (string) $contact['email'] : null;
        $this->timezone = (string) ($hospital->timezone ?: 'Africa/Kampala');
        $this->currentLogo = $hospital->logo;
    }

    /**
     * The URL to draw the chosen file at, or null if it cannot be drawn.
     *
     * `temporaryUrl()` THROWS on anything Livewire will not preview, so a
     * dropped PDF took the whole page down with an exception instead of
     * showing the validation error that was waiting for it.
     */
    public function logoPreviewUrl(): ?string
    {
        return $this->logo !== null && $this->logo->isPreviewable()
            ? $this->logo->temporaryUrl()
            : null;
    }

    /** Say so at once, rather than at the bottom of the form after saving. */
    public function updatedLogo(): void
    {
        $this->validateOnly('logo');
    }

    protected function rules(): array
    {
        return HospitalProfileRequest::rulesFor() + [
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif', 'max:2048'],
        ];
    }

    public function save(): void
    {
        $this->authorizeSetup();
        $data = $this->validate();

        if (blank($data['phone']) && blank($data['email'])) {
            $this->addError('phone', 'Add a phone number or an email address — patients and insurers need a way to reach you.');

            return;
        }

        $hospital = $this->hospital();
        $hospital->name = $data['name'];
        $hospital->address = $data['address'];
        $hospital->timezone = $data['timezone'];

        if ($this->logo !== null) {
            $hospital->logo = app(AvatarService::class)->storeLogo($this->logo);
            $this->logo = null;
        }
        // Into `profile`, which is the key DocumentBrand prints from — and
        // merged, because the letterhead page owns website and licence number
        // in the same place and setup must not wipe them.
        $stored = $hospital->settings ?? [];
        $profile = is_array($stored['profile'] ?? null) ? $stored['profile'] : [];

        $hospital->settings = array_merge($stored, [
            'profile' => array_filter(array_merge($profile, [
                'phone' => $data['phone'] ?: null,
                'email' => $data['email'] ?: null,
            ]), fn ($value) => $value !== null),
        ]);
        $hospital->save();

        $this->currentLogo = $hospital->logo;

        $this->stepCompleted('Hospital details saved.');
    }

    public function render()
    {
        $this->authorizeSetup();

        return view('livewire.onboarding.steps.profile', ['timezones' => self::TIMEZONES]);
    }
}
