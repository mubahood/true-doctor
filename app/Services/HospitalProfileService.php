<?php

namespace App\Services;

use App\Models\Hospital;
use App\Support\BrandColour;
use App\Support\HospitalBrand;
use App\Support\OnboardingStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The hospital's own identity — the letterhead on everything it prints, and
 * its brand (Settings → Hospital letterhead). Shared by that page and the API.
 *
 * Address lives on the hospital row; the contact lines live in
 * `settings['profile']`; the brand (a colour, a tagline, a wide logo) in
 * `settings['brand']`; and the footer line is a billing setting that this
 * writes into the same key as Settings → Billing.
 */
class HospitalProfileService
{
    public function __construct(private readonly AvatarService $images, private readonly OnboardingStatus $onboarding) {}

    /** @return array<string, list<string|\Closure>> */
    public function rules(): array
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
            'brand_colour' => ['nullable', 'string', 'max:7', function (string $attribute, mixed $value, \Closure $fail): void {
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

    /**
     * What the letterhead page shows for a hospital.
     *
     * @return array<string,mixed>
     */
    public function current(Hospital $hospital): array
    {
        // Through OnboardingStatus, so details given during setup show up
        // rather than the page looking empty over answers already on file.
        $profile = $this->onboarding->contact($hospital);
        $brand = new HospitalBrand($hospital);
        $billing = is_array($hospital->settings['billing'] ?? null) ? $hospital->settings['billing'] : [];

        return [
            'name' => (string) $hospital->name,
            'address' => $hospital->address,
            'phone' => $profile['phone'] ?? null,
            'email' => $profile['email'] ?? null,
            'website' => $profile['website'] ?? null,
            'registration' => $profile['registration'] ?? null,
            'footer' => $billing['invoice_footer'] ?? null,
            'logo_url' => $brand->logoUrl(),
            'wordmark_url' => $brand->wordmarkUrl(),
            'brand_colour' => $brand->colour(),
            'tagline' => $brand->tagline(),
        ];
    }

    /**
     * Save the letterhead and brand. $data holds the validated text fields
     * (see rules()); the two images are passed separately.
     *
     * @param  array<string,mixed>  $data
     */
    public function save(Hospital $hospital, array $data, ?UploadedFile $logo = null, ?UploadedFile $wordmark = null): Hospital
    {
        $hospital->name = $data['name'];
        $hospital->address = $this->blank($data['address'] ?? null);

        if ($logo !== null) {
            $previous = $hospital->logo;
            $hospital->logo = $this->images->storeLogo($logo);

            // The old file is nobody's once the new one is in place.
            $this->forget($previous);
        }

        $stored = $hospital->settings ?? [];
        $brand = is_array($stored['brand'] ?? null) ? $stored['brand'] : [];

        if ($wordmark !== null) {
            // Wider than the square logo's box: it is read across a letterhead.
            $previousWordmark = $brand['wordmark'] ?? null;
            $brand['wordmark'] = $this->images->storeLogo($wordmark, 900, 300);
            $this->forget(is_string($previousWordmark) ? $previousWordmark : null);
        }
        $brand['colour'] = BrandColour::normalise($this->blank($data['brand_colour'] ?? null));
        $brand['tagline'] = $this->blank($data['tagline'] ?? null);
        $brand = array_filter($brand, fn ($value) => $value !== null && $value !== '');

        $hospital->settings = array_merge($stored, [
            // One store, shared with setup (OnboardingStatus::contact).
            'profile' => array_filter(array_merge(
                is_array($stored['profile'] ?? null) ? $stored['profile'] : [],
                [
                    'phone' => $this->blank($data['phone'] ?? null),
                    'email' => $this->blank($data['email'] ?? null),
                    'website' => $this->blank($data['website'] ?? null),
                    'registration' => $this->blank($data['registration'] ?? null),
                ],
            ), fn ($value) => $value !== null),
            // The footer is a billing setting and stays one.
            'billing' => array_merge(
                is_array($stored['billing'] ?? null) ? $stored['billing'] : [],
                ['invoice_footer' => $this->blank($data['footer'] ?? null)],
            ),
            'brand' => $brand,
        ]);

        $hospital->save();

        return $hospital;
    }

    public function removeLogo(Hospital $hospital): Hospital
    {
        $this->forget($hospital->logo);
        $hospital->logo = null;
        $hospital->save();

        return $hospital;
    }

    public function removeWordmark(Hospital $hospital): Hospital
    {
        $stored = $hospital->settings ?? [];
        $brand = is_array($stored['brand'] ?? null) ? $stored['brand'] : [];
        $this->forget(is_string($brand['wordmark'] ?? null) ? $brand['wordmark'] : null);
        unset($brand['wordmark']);
        $stored['brand'] = $brand;
        $hospital->settings = $stored;
        $hospital->save();

        return $hospital;
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
}
