<?php

namespace App\Livewire\Settings;

use App\Services\AvatarService;
use App\Support\BrandColour;
use App\Support\HospitalBrand;
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
 *
 * The brand — a colour and a wide logo — lives in `settings['brand']` and is
 * read through HospitalBrand: the colour re-tints the system's screens and is
 * the accent on every document; the wide logo heads the letterhead and, on a
 * standalone installation, the sign-in page.
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

    /** `#RRGGBB`, or empty for True-Doctor's own blue. */
    public ?string $brandColour = null;

    /** A horizontal logo for letterheads and the sign-in page. */
    public ?TemporaryUploadedFile $wordmark = null;

    public ?string $currentWordmark = null;

    /** A short line in the hospital's own words, shown on its sign-in page and emails. */
    public ?string $tagline = null;

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

        $brand = new HospitalBrand($hospital);
        $this->brandColour = $brand->colour();
        $this->currentWordmark = $brand->wordmarkPath();
        $this->tagline = $brand->tagline();
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

    public function wordmarkPreviewUrl(): ?string
    {
        return $this->wordmark !== null && $this->wordmark->isPreviewable()
            ? $this->wordmark->temporaryUrl()
            : null;
    }

    /** The colour the preview draws with: the one being typed when it is usable, else the default. */
    public function previewColour(): string
    {
        $colour = BrandColour::normalise($this->brandColour);

        return $colour !== null && BrandColour::readableOnWhite($colour) ? $colour : '#0E6B63';
    }

    /** @return array<string, list<string|\Closure>> */
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
            'wordmark' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif', 'max:2048'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'brandColour' => ['nullable', 'string', 'max:7', function (string $attribute, mixed $value, \Closure $fail): void {
                $colour = BrandColour::normalise(is_string($value) ? $value : null);
                if ($colour === null) {
                    $fail('Enter a colour as six hexadecimal digits, like #1C4795.');
                } elseif (! BrandColour::readableOnWhite($colour)) {
                    $fail(sprintf(
                        'This colour is too light for white text on buttons (contrast %.1f:1; at least %.1f:1 is needed). Choose a darker shade.',
                        // Rounded DOWN: a refused 4.48 must never read as the 4.5 it missed.
                        floor(BrandColour::contrastWithWhite($colour) * 10) / 10, BrandColour::MIN_CONTRAST,
                    ));
                }
            }],
        ];
    }

    /** @return array<string,string> */
    protected function validationAttributes(): array
    {
        return ['brandColour' => 'brand colour', 'wordmark' => 'wide logo'];
    }

    /** Say so at once, rather than at the bottom of the form after saving. */
    public function updatedLogo(): void
    {
        $this->validateOnly('logo');
    }

    public function updatedWordmark(): void
    {
        $this->validateOnly('wordmark');
    }

    public function updatedBrandColour(): void
    {
        // Empty means True-Doctor blue, which is always allowed — and clears
        // whatever the last value was told.
        if (trim((string) $this->brandColour) === '') {
            $this->resetErrorBag('brandColour');

            return;
        }

        $this->validateOnly('brandColour');
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
        $brand = is_array($stored['brand'] ?? null) ? $stored['brand'] : [];

        if ($this->wordmark !== null) {
            // Wider than the square logo's box: it is read across a letterhead.
            $previousWordmark = $brand['wordmark'] ?? null;
            $brand['wordmark'] = $images->storeLogo($this->wordmark, 900, 300);
            $this->forget(is_string($previousWordmark) ? $previousWordmark : null);
        }
        $brand['colour'] = BrandColour::normalise($this->blank($data['brandColour'] ?? null));
        $brand['tagline'] = $this->blank($data['tagline'] ?? null);
        $brand = array_filter($brand, fn ($value) => $value !== null && $value !== '');

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
            'brand' => $brand,
        ]);

        $hospital->save();

        $this->currentLogo = $hospital->logo;
        $this->logo = null;
        $this->currentWordmark = $brand['wordmark'] ?? null;
        $this->wordmark = null;
        $this->brandColour = $brand['colour'] ?? null;
        $this->tagline = $brand['tagline'] ?? null;

        // The menu, buttons and tab icon are drawn by the layout, which is
        // persisted across navigation: reload so the new brand shows at once.
        session()->flash('success', 'Letterhead and brand saved.');
        $this->redirectRoute('admin.settings.hospital', navigate: false);
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

        // The menu and tab icon show this logo and are persisted across
        // navigation: reload, as save() does, so they let go of it at once.
        session()->flash('success', 'Logo removed.');
        $this->redirectRoute('admin.settings.hospital', navigate: false);
    }

    public function removeWordmark(HospitalSettings $settings): void
    {
        $this->authorizeManage();

        $hospital = $settings->hospital();
        abort_if($hospital === null, 404, 'No hospital in context.');

        $stored = $hospital->settings ?? [];
        $brand = is_array($stored['brand'] ?? null) ? $stored['brand'] : [];
        $this->forget(is_string($brand['wordmark'] ?? null) ? $brand['wordmark'] : null);
        unset($brand['wordmark']);
        $stored['brand'] = $brand;
        $hospital->settings = $stored;
        $hospital->save();

        $this->currentWordmark = null;
        $this->dispatch('toast', message: 'Wide logo removed.', type: 'success');
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
