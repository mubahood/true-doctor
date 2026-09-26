<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What an advertisement asked us to show, and where it came from.
 *
 * Everything here arrives in a URL that anybody can type, so nothing is
 * trusted: `section`, `module` and `plan` are matched against a closed list
 * and anything else becomes null, and the free-text values (UTMs, click ids,
 * the ad's tracking-template parameters) are length-capped and stripped of
 * control characters before they go anywhere near a database or a page.
 *
 * The specification's rule is the one that matters: **an unknown value must
 * fall back to the home page and never error.** Google rejects a campaign
 * whose landing pages 404, so a typo in an ad must degrade to the front door
 * rather than to a stack trace.
 */
final readonly class LandingIntent
{
    /** Sections an ad may ask for, and the route each one means. */
    public const SECTIONS = [
        'pricing' => 'pricing',
        'signup' => 'register',
        'demo' => 'test-login',
        'modules' => 'features',
        'security' => 'security',
        'contact' => 'contact',
    ];

    /** Module anchors on the product page. */
    public const MODULES = [
        'patients', 'appointments', 'pharmacy',
        'lab-radiology', 'inpatient', 'billing', 'offline',
    ];

    /** Plan slugs a price asset may highlight. */
    public const PLANS = ['starter', 'professional', 'enterprise'];

    /** UTM parameters carried through and stored. */
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /**
     * Click ids, in the order they are believed.
     *
     * `gbraid` and `wbraid` are what Google sends in place of `gclid` for
     * many iOS clicks; without them those paid clicks read as direct
     * traffic. `msclkid` and `fbclid` are Microsoft's and Meta's — kept on
     * the hit so a click from another network is not mistaken for nothing.
     */
    public const CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid'];

    /**
     * What else an ad's tracking template may say, and nothing more.
     *
     * Google's ValueTrack parameters plus the two Google now appends by
     * itself (`gad_source`, `gad_campaignid`). An allow-list, because a URL
     * is somebody else's free text and storing all of it would store
     * whatever anybody chose to type into it.
     */
    public const AD_PARAMS = [
        'keyword', 'matchtype', 'network', 'device', 'adgroupid', 'campaignid',
        'creative', 'placement', 'adposition', 'target', 'feeditemid',
        'loc_physical_ms', 'loc_interest_ms', 'gad_source', 'gad_campaignid',
    ];

    /**
     * The campaign's own names for its assets, so the report reads in the
     * words the Google Ads screen uses rather than in query strings.
     */
    private const ASSET_NAMES = [
        'pricing' => 'Pricing & Plans',
        'signup' => 'Start Free Trial',
        'demo' => 'See a Live Demo',
        'security' => 'Security & Privacy',
        'contact' => 'Contact Sales',
        'modules' => 'Product page',
        'module:patients' => 'Patient Records & EMR',
        'module:appointments' => 'Appointments & Queue',
        'module:pharmacy' => 'Pharmacy Management',
        'module:lab-radiology' => 'Lab & Radiology',
        'module:inpatient' => 'Inpatient & Ward System',
        'module:billing' => 'Billing & Insurance',
        'module:offline' => 'Offline Field Mode',
        'plan:starter' => 'Starter Plan (price asset)',
        'plan:professional' => 'Professional Plan (price asset)',
        'plan:enterprise' => 'Enterprise Plan (price asset)',
    ];

    private function __construct(
        public ?string $section,
        public ?string $module,
        public ?string $plan,
        /** @var array<string,string> click id type => value, first believed first */
        public array $clickIds,
        /** @var array<string,string> */
        public array $utm,
        /** @var array<string,string> */
        public array $adParams,
        public ?string $referrerHost,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $clickIds = [];
        foreach (self::CLICK_IDS as $key) {
            if (($value = self::text($request->query($key), 191)) !== null) {
                $clickIds[$key] = $value;
            }
        }

        $adParams = [];
        foreach (self::AD_PARAMS as $key) {
            if (($value = self::text($request->query($key), 100)) !== null) {
                $adParams[$key] = $value;
            }
        }

        return new self(
            section: self::oneOf($request->query('section'), array_keys(self::SECTIONS)),
            module: self::oneOf($request->query('module'), self::MODULES),
            plan: self::oneOf($request->query('plan'), self::PLANS),
            clickIds: $clickIds,
            utm: self::utm($request),
            adParams: $adParams,
            referrerHost: self::host($request->headers->get('referer')),
        );
    }

    /**
     * The same shape rebuilt from what an earlier request stored.
     *
     * Used at sign-up, when the POST itself carries none of this and the
     * only record of the ad is what was put aside when they arrived.
     *
     * @param  array<string,mixed>  $stored  the output of toAttributes()
     */
    public static function fromStored(array $stored): self
    {
        $pick = fn (array $keys, int $limit) => array_filter(
            array_map(fn ($k) => self::text($stored[$k] ?? null, $limit), array_combine($keys, $keys)),
            fn ($v) => $v !== null,
        );

        return new self(
            section: self::oneOf($stored['landing_section'] ?? null, array_keys(self::SECTIONS)),
            module: self::oneOf($stored['landing_module'] ?? null, self::MODULES),
            plan: self::oneOf($stored['landing_plan'] ?? null, self::PLANS),
            clickIds: $pick(['gclid', 'gbraid', 'wbraid'], 191),
            utm: $pick(self::UTM_KEYS, 160),
            adParams: [],
            referrerHost: null,
        );
    }

    public function gclid(): ?string
    {
        return $this->clickIds['gclid'] ?? null;
    }

    /** The click id to believe, and what kind it is — or [null, null]. */
    public function clickId(): array
    {
        foreach ($this->clickIds as $type => $value) {
            return [$value, $type];
        }

        return [null, null];
    }

    /** The search term: the tracking suffix's utm_term, else ValueTrack's {keyword}. */
    public function keyword(): ?string
    {
        return $this->utm['utm_term'] ?? $this->adParams['keyword'] ?? null;
    }

    /** Nothing at all in the URL that we were looking for. */
    public function isEmpty(): bool
    {
        return $this->section === null
            && $this->module === null
            && $this->plan === null
            && $this->clickIds === []
            && $this->utm === [];
    }

    /** Something worth remembering for thirty days. */
    public function hasAttribution(): bool
    {
        return $this->clickIds !== [] || $this->utm !== [];
    }

    /**
     * The route a `section` means, or null to stay where we are.
     *
     * `signup` deliberately maps to the sign-up form rather than to a page
     * about signing up: the ad said "Start Free Trial", and a click on that
     * which lands on a marketing page has wasted somebody's intent.
     */
    public function route(): ?string
    {
        return $this->section === null ? null : (self::SECTIONS[$this->section] ?? null);
    }

    /**
     * The fragment to open the destination at.
     *
     * A real anchor, so the page works with no JavaScript at all — the
     * browser does the scrolling and the highlight is decoration on top.
     */
    public function fragment(): ?string
    {
        if ($this->section === 'modules' && $this->module !== null) {
            return $this->module;
        }

        if ($this->section === 'contact') {
            return 'enquiry';
        }

        return null;
    }

    /**
     * What to carry into the destination's query string.
     *
     * The UTMs, click ids and tracking parameters travel, so a redirect does
     * not lose the attribution before anything has recorded it; `plan`
     * travels so the pricing page knows which card to mark and the sign-up
     * form knows what to pre-select.
     *
     * @return array<string,string>
     */
    public function forwardQuery(): array
    {
        $query = $this->utm + $this->clickIds + $this->adParams;

        if ($this->plan !== null) {
            $query['plan'] = $this->plan;
        }

        if ($this->section === 'modules' && $this->module !== null) {
            $query['module'] = $this->module;
        }

        return $query;
    }

    /** @return array<string,mixed> the columns a hospital or session stores */
    public function toAttributes(): array
    {
        return [
            'utm_source' => $this->utm['utm_source'] ?? null,
            'utm_medium' => $this->utm['utm_medium'] ?? null,
            'utm_campaign' => $this->utm['utm_campaign'] ?? null,
            'utm_content' => $this->utm['utm_content'] ?? null,
            'utm_term' => $this->keyword(),
            'gclid' => $this->clickIds['gclid'] ?? null,
            'gbraid' => $this->clickIds['gbraid'] ?? null,
            'wbraid' => $this->clickIds['wbraid'] ?? null,
            'landing_section' => $this->section,
            'landing_module' => $this->module,
            'landing_plan' => $this->plan,
        ];
    }

    /**
     * The campaign's name for the asset a hit came through.
     *
     * The most specific thing the URL named wins — a module sitelink is
     * "Pharmacy Management", not "Product page"; a price asset is the plan.
     */
    public static function assetName(?string $section, ?string $module, ?string $plan): string
    {
        if ($section === 'modules' && $module !== null) {
            return self::ASSET_NAMES['module:'.$module] ?? 'Product page';
        }

        if ($section === 'pricing' && $plan !== null) {
            return self::ASSET_NAMES['plan:'.$plan] ?? 'Pricing & Plans';
        }

        if ($section === null) {
            return 'Main ad (home page)';
        }

        return self::ASSET_NAMES[$section] ?? 'Main ad (home page)';
    }

    /**
     * The anchor id for a module card on the product page.
     *
     * Keyed off the card's own title so the page and this list cannot drift
     * apart silently — a renamed card loses its anchor visibly (the sitelink
     * stops scrolling) rather than pointing at the wrong block.
     */
    public static function anchorFor(string $title): ?string
    {
        return match (html_entity_decode(strip_tags($title))) {
            'Patient records' => 'patients',
            'Appointments' => 'appointments',
            'Pharmacy & stock' => 'pharmacy',
            'Laboratory & radiology' => 'lab-radiology',
            'Inpatient care' => 'inpatient',
            'Billing & payments' => 'billing',
            'Field Mode' => 'offline',
            default => null,
        };
    }

    // ── Reading a URL nobody vouched for ─────────────────────────────────

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        // `?section[]=x` arrives as an array, and casting one to a string is
        // a warning — which this application's handler turns into an
        // exception, on a public landing page, on a live campaign.
        if (! is_string($value)) {
            return null;
        }

        $value = Str::lower(trim($value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * A free-text parameter, made safe to store and to print.
     *
     * Control characters out (a newline in a UTM value corrupts a CSV export
     * and can forge a row), length capped to the column, and an empty string
     * becomes null so "absent" and "blank" are the same thing everywhere.
     * Malformed UTF-8 is refused outright rather than half-cleaned.
     */
    private static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $clean = trim(mb_substr($clean, 0, $limit));

        return $clean === '' ? null : $clean;
    }

    /**
     * Source and medium are lower-cased: they are a small vocabulary
     * ("google", "cpc") that people type by hand in any case, and "Google /
     * CPC" counted apart from "google / cpc" splits one channel in two.
     * Campaign, content and term keep their case — they are names.
     *
     * @return array<string,string>
     */
    private static function utm(Request $request): array
    {
        $utm = [];

        foreach (self::UTM_KEYS as $key) {
            $short = $key === 'utm_source' || $key === 'utm_medium';
            $value = self::text($request->query($key), $short ? 120 : 160);

            if ($value !== null) {
                $utm[$key] = $short ? Str::lower($value) : $value;
            }
        }

        return $utm;
    }

    /** Just the host of a referrer — the path can carry anything. */
    private static function host(?string $referrer): ?string
    {
        if ($referrer === null || $referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? Str::lower(mb_substr($host, 0, 191)) : null;
    }
}
