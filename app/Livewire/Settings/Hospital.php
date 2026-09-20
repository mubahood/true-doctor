<?php

namespace App\Livewire\Settings;

use App\Services\AvatarService;
use App\Support\HospitalSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The hospital's own identity — the letterhead on everything it prints.
 *
 * `hospitals.logo` has existed since the first migration with nothing able to
 * set it and nothing reading it, and a hospital's phone number lived nowhere
 * at all. So an invoice a patient took home carried a name, an address and no
 * way to ring anybody about it.
 *
 * What is set here appears on every generated document through DocumentBrand:
 * invoices, receipts, lab and radiology reports, discharge summaries, insurance
 * statements and the patient ID card. There is a live preview on the page
 * because nobody should have to raise an invoice to find out what their
 * letterhead looks like.
 *
 * Address lives on the hospital row (it always has); the contact lines live in
 * `settings['profile']`, because five columns nothing queries, joins or sorts
 * by is five migrations for five strings that are only ever printed.
 */
#[Layout('layouts.admin')]
class Hospital extends Component
{
    use WithFileUploads;

    public string $name = '';

    public ?string $address = null;

    public ?string $phone = null;

    public ?string $email = null;

    public ?string $website = null;

    /** A licence or tax number — whatever this country expects on a document. */
    public ?string $registration = null;

    /** The one line every document ends on. Shared with the billing settings. */
    public ?string $footer = null;

    public ?TemporaryUploadedFile $logo = null;

    /** The stored path, so the page can show what is already set. */
    public ?string $currentLogo = null;

    public function mount(HospitalSettings $settings): void
    {
        $this->authorizeManage();

        // A super-admin reading this page has no hospital in context, and the
        // menu offers it to them exactly as it offers Billing settings. The
        // form renders empty rather than 404ing; `save()` is where a hospital
        // is actually required.
        $hospital = $settings->hospital();

        // Through OnboardingStatus, so details given during setup show up here
        // rather than the page looking empty over answers already on file.
        $profile = $hospital === null
            ? []
            : app(\App\Support\OnboardingStatus::class)->contact($hospital);

        $this->name = $hospital === null ? '' : (string) $hospital->name;
        $this->address = $hospital?->address;
        $this->phone = $profile['phone'] ?? null;
        $this->email = $profile['email'] ?? null;
        $this->website = $profile['website'] ?? null;
        $this->registration = $profile['registration'] ?? null;
        $this->footer = $settings->get('invoice_footer');
        $this->currentLogo = $hospital?->logo;
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

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:191'],
            'website' => ['nullable', 'string', 'max:191'],
            'registration' => ['nullable', 'string', 'max:120'],
            'footer' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif', 'max:2048'],
        ];
    }

    /** Say so at once, rather than at the bottom of the form after saving. */
    public function updatedLogo(): void
    {
        $this->validateOnly('logo');
    }

    public function save(HospitalSettings $settings, AvatarService $images): void
    {
        $this->authorizeManage();

        $data = $this->validate();

        $hospital = $settings->hospital();
        abort_if($hospital === null, 404, 'No hospital in context.');

        $hospital->name = $data['name'];
        $hospital->address = $this->blank($data['address'] ?? null);

        if ($this->logo !== null) {
            $previous = $hospital->logo;
            $hospital->logo = $images->storeLogo($this->logo);

            // The old file is nobody's once the new one is in place, and a
            // settings page that leaves a trail of orphans behind it fills a
            // disk one upload at a time.
            $this->forget($previous);
        }

        $stored = $hospital->settings ?? [];

        $hospital->settings = array_merge($stored, [
            // One store, shared with setup (OnboardingStatus::contact) — the
            // two used to write different keys, so a hospital could fill this
            // in and still be held in the wizard for want of the same details.
            'profile' => array_filter(array_merge(
                is_array($stored['profile'] ?? null) ? $stored['profile'] : [],
                [
                    'phone' => $this->blank($data['phone'] ?? null),
                    'email' => $this->blank($data['email'] ?? null),
                    'website' => $this->blank($data['website'] ?? null),
                    'registration' => $this->blank($data['registration'] ?? null),
                ],
            ), fn ($value) => $value !== null),
            // The footer is a billing setting and stays one — this page writes
            // into the same key rather than opening a second copy of it.
            'billing' => array_merge(
                is_array($stored['billing'] ?? null) ? $stored['billing'] : [],
                ['invoice_footer' => $this->blank($data['footer'] ?? null)],
            ),
        ]);

        $hospital->save();

        $this->currentLogo = $hospital->logo;
        $this->logo = null;

        $this->dispatch('toast', message: 'Letterhead saved.', type: 'success');
    }

    public function removeLogo(HospitalSettings $settings): void
    {
        $this->authorizeManage();

        $hospital = $settings->hospital();
        abort_if($hospital === null, 404, 'No hospital in context.');

        $this->forget($hospital->logo);
        $hospital->logo = null;
        $hospital->save();

        $this->currentLogo = null;
        $this->dispatch('toast', message: 'Logo removed.', type: 'success');
    }

    /** Silent: a file that has already gone is the outcome we wanted anyway. */
    private function forget(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable) {
            // nothing to do about it, and nothing depending on it
        }
    }

    private function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function authorizeManage(): void
    {
        abort_unless(Auth::user()?->can('manage-settings') === true, 403);
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.settings.hospital')->title('Hospital letterhead');
    }
}
