<?php

namespace App\Support;

use App\Models\Hospital;
use Illuminate\Support\Facades\Storage;

/**
 * How a hospital looks on screen: its colour, its logo and its wide logo.
 *
 * Everything here is opt-in. A hospital that has set nothing gets exactly the
 * True-Doctor look it always had; one that has set a colour sees its own
 * buttons, links and menu; one with a logo sees it at the top of the menu and
 * on the browser tab.
 *
 * Two logos, because one shape cannot serve every place: the square `logo`
 * fits the 30-pixel menu mark, the tab icon and the mobile app, and the wide
 * `settings['brand']['wordmark']` — a horizontal logo with the name in it —
 * fits a printed letterhead and the sign-in page, where a square mark would
 * be a postage stamp.
 */
final class HospitalBrand
{
    /** @var array<string, string|null> URL per stored path, null when the file is not there */
    private array $urls = [];

    private ?bool $square = null;

    public function __construct(private readonly ?Hospital $hospital) {}

    /** The signed-in user's hospital — none for a super-admin. */
    public static function current(): self
    {
        return new self(app(HospitalSettings::class)->hospital());
    }

    /**
     * Whose sign-in page this is. A standalone installation belongs to one
     * organisation, so its sign-in page is theirs; the online service's is
     * shared by every hospital on it and stays True-Doctor's.
     */
    public static function forSignIn(): self
    {
        if (! Edition::isStandalone()) {
            return new self(null);
        }

        // Once per web request — the layout, the page and an error view all
        // ask — but never cached across a long-running worker, which renders
        // emails for hours and must see a brand changed meanwhile.
        $request = app()->runningInConsole() ? null : request();
        if ($request?->attributes->get(self::class) instanceof self) {
            return $request->attributes->get(self::class);
        }

        try {
            $brand = new self(Hospital::query()->withoutGlobalScopes()->oldest('id')->first());
        } catch (\Throwable) {
            // Before installation there is no table to read; the page still draws.
            $brand = new self(null);
        }
        $request?->attributes->set(self::class, $brand);

        return $brand;
    }

    /**
     * Whose brand an email to this person wears: their hospital's; on a
     * standalone installation the one organisation; otherwise True-Doctor's.
     * Emails are rendered by the queue worker, outside any request.
     */
    public static function forUser(?\App\Models\User $user): self
    {
        $hospital = $user?->hospital_id ? Hospital::query()->withoutGlobalScopes()->find($user->hospital_id) : null;

        return $hospital !== null ? new self($hospital) : self::forSignIn();
    }

    /**
     * Whether the hospital has made the system its own — a colour or a logo.
     * A name alone is not a brand: a page that swapped True-Doctor's look for
     * a plain name would only look unfinished.
     */
    public function isSet(): bool
    {
        return $this->colour() !== null || $this->wordmarkUrl() !== null || $this->logoUrl() !== null;
    }

    /** A short line in the hospital's own words, e.g. "For a better smile". */
    public function tagline(): ?string
    {
        return $this->clean($this->settings()['tagline'] ?? null);
    }

    /**
     * How to reach the hospital, as its letterhead gives it.
     *
     * @return array{address:?string, phone:?string, email:?string, website:?string}
     */
    public function contact(): array
    {
        $profile = $this->hospital === null ? [] : app(OnboardingStatus::class)->contact($this->hospital);

        return [
            'address' => $this->clean($this->hospital?->address),
            'phone' => $this->clean($profile['phone'] ?? null),
            'email' => $this->clean($profile['email'] ?? null),
            'website' => $this->clean($profile['website'] ?? null),
        ];
    }

    /** The domain of the hospital's own email, for a sign-in placeholder ("you@clinic.org"). */
    public function emailDomain(): ?string
    {
        $email = $this->contact()['email'];
        $domain = $email !== null && str_contains($email, '@') ? strtolower(substr($email, strrpos($email, '@') + 1)) : null;

        return $domain !== null && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) ? $domain : null;
    }

    /** "you@their-domain" on a hospital's own sign-in pages, else the generic hint. */
    public function emailPlaceholder(): string
    {
        return 'you@'.(($this->isSet() ? $this->emailDomain() : null) ?? 'hospital.org');
    }

    /** The shades of the colour, for anything that needs more than the CSS variables. */
    public function palette(): ?array
    {
        $colour = $this->colour();

        return $colour === null ? null : BrandColour::palette($colour);
    }

    public function hospital(): ?Hospital
    {
        return $this->hospital;
    }

    public function name(): ?string
    {
        $name = trim((string) $this->hospital?->name);

        return $name === '' ? null : $name;
    }

    /**
     * The stored colour, or null when there is none — or when it would not be
     * readable, which the settings form refuses but an imported row might not.
     */
    public function colour(): ?string
    {
        $colour = BrandColour::normalise($this->settings()['colour'] ?? null);

        return $colour !== null && BrandColour::readableOnWhite($colour) ? $colour : null;
    }

    public function logoPath(): ?string
    {
        return $this->clean($this->hospital?->logo);
    }

    public function wordmarkPath(): ?string
    {
        return $this->clean($this->settings()['wordmark'] ?? null);
    }

    public function logoUrl(): ?string
    {
        return $this->url($this->logoPath());
    }

    /**
     * The logo for wide places — a sign-in card, an email header, an error
     * page: the wide logo, else a logo of any shape but square (which is a
     * wide logo in all but name). One rule, so those places never disagree.
     */
    public function wideUrl(): ?string
    {
        return $this->wordmarkUrl() ?? ($this->markUrl() !== null ? null : $this->logoUrl());
    }

    /**
     * The logo for small square places — the menu mark, the browser tab, the
     * home-screen icon — or null to keep True-Doctor's own.
     *
     * Only a roughly square logo qualifies. Before the wide logo existed, the
     * one logo slot was the letterhead's, so many hospitals filled it with a
     * horizontal logo; shrunk into a 30-pixel box or a tab icon it becomes an
     * unreadable sliver, and nobody asked for it to appear there.
     */
    public function markUrl(): ?string
    {
        $url = $this->logoUrl();

        return $url !== null && $this->logoIsSquare() ? $url : null;
    }

    public function wordmarkUrl(): ?string
    {
        return $this->url($this->wordmarkPath());
    }

    /**
     * The colour as CSS custom properties — `--br`, `--br-d`, `--br-soft` and
     * the variables the back office aliases to them — or an empty string.
     *
     * @param  array{base:list<string>, dark:list<string>, soft:list<string>}  $names
     */
    public function cssVariables(array $names): string
    {
        $colour = $this->colour();
        if ($colour === null) {
            return '';
        }

        $palette = BrandColour::palette($colour);
        $declarations = [];
        foreach ($names as $shade => $variables) {
            foreach ($variables as $variable) {
                $declarations[] = $variable.':'.$palette[$shade];
            }
        }

        return ':root{'.implode(';', $declarations).'}';
    }

    /** The back office's brand variables (resources/css/admin.css). */
    public function adminCss(): string
    {
        return $this->cssVariables([
            'base' => ['--br', '--ac', '--info'],
            'dark' => ['--br-d'],
            'soft' => ['--br-soft', '--info-soft'],
        ]);
    }

    /** The sign-in pages' brand variables (resources/css/auth.css). */
    public function authCss(): string
    {
        return $this->cssVariables([
            // --br as well: the picture check (human-check.css) is drawn with it.
            'base' => ['--pri', '--br'],
            'dark' => ['--pri-d'],
            'soft' => ['--pri-soft'],
        ]);
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $brand = $this->hospital?->settings['brand'] ?? null;

        return is_array($brand) ? $brand : [];
    }

    private function logoIsSquare(): bool
    {
        if ($this->square !== null) {
            return $this->square;
        }

        try {
            $size = @getimagesize(Storage::disk('public')->path((string) $this->logoPath()));
        } catch (\Throwable) {
            $size = false;
        }

        return $this->square = is_array($size) && $size[1] > 0
            && $size[0] / $size[1] >= 0.75 && $size[0] / $size[1] <= 1.34;
    }

    /** A URL only for a file that is really there — a missing one would draw an empty box. */
    private function url(?string $path): ?string
    {
        if ($path === null || str_contains($path, '..')) {
            return null;
        }

        if (! array_key_exists($path, $this->urls)) {
            try {
                $disk = Storage::disk('public');
                $this->urls[$path] = $disk->exists($path) ? $disk->url($path) : null;
            } catch (\Throwable) {
                $this->urls[$path] = null;
            }
        }

        return $this->urls[$path];
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
