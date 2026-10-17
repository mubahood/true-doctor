<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Which guide answers the question a screen raises (roadmap 06 §4).
 *
 * ROUTES is the one mapping from a back-office screen (its route name, or a
 * prefix of it) to a guide in config/guides.php. The page header and the
 * topbar's help menu both ask `forRoute()`, so a screen and its "Help" link
 * cannot disagree. A screen with no guide of its own points at the hub.
 *
 * The guides are pages of the public site. A hospital's own copy does not
 * carry that site, so there the link goes to the guides online.
 */
final class HelpGuides
{
    /** route name or "prefix." => guide slug, first match wins (so put exact names before prefixes). */
    public const ROUTES = [
        // Setting the clinic up.
        'admin.settings.hospital' => 'register-a-private-clinic-in-uganda',
        'admin.settings.practice' => 'register-a-private-clinic-in-uganda',
        'admin.onboarding' => 'register-a-private-clinic-in-uganda',
        'admin.departments.' => 'register-a-private-clinic-in-uganda',
        'admin.rooms.' => 'register-a-private-clinic-in-uganda',
        'admin.staff.' => 'register-a-private-clinic-in-uganda',
        'admin.users.' => 'register-a-private-clinic-in-uganda',

        // The Ministry's reports.
        'admin.reports.' => 'hmis-105-explained',
        'admin.diagnoses.' => 'hmis-105-explained',

        // No network.
        'admin.offline.' => 'running-a-clinic-with-no-internet',
        'admin.offline-devices.' => 'running-a-clinic-with-no-internet',

        // Prices.
        'admin.services.' => 'setting-clinic-prices-that-cover-costs',
        'admin.settings.billing' => 'setting-clinic-prices-that-cover-costs',
        'admin.lab-tests.' => 'setting-clinic-prices-that-cover-costs',

        // Insurers.
        'admin.insurance-claims.' => 'getting-paid-by-insurers-in-uganda',
        'admin.insurance-providers.' => 'getting-paid-by-insurers-in-uganda',

        // The money.
        'admin.invoices.' => 'stopping-money-leaks-at-the-cashier',
        'admin.cashier.' => 'stopping-money-leaks-at-the-cashier',
        'admin.payments.' => 'stopping-money-leaks-at-the-cashier',
        'admin.cards.' => 'stopping-money-leaks-at-the-cashier',
        'admin.card-records.' => 'stopping-money-leaks-at-the-cashier',
        'admin.finance.' => 'stopping-money-leaks-at-the-cashier',
        'admin.financial-years.' => 'stopping-money-leaks-at-the-cashier',
    ];

    /** @return array<string,array<string,mixed>> slug => guide */
    public static function all(): array
    {
        return (array) config('guides.guides', []);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $slug): ?array
    {
        $guide = self::all()[$slug] ?? null;

        return is_array($guide) ? $guide : null;
    }

    /** The guide for a screen, or null when it has none of its own. */
    public static function forRoute(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        foreach (self::ROUTES as $pattern => $slug) {
            $hit = str_ends_with($pattern, '.') ? str_starts_with($name, $pattern) : $name === $pattern;
            if ($hit && self::find($slug) !== null) {
                return $slug;
            }
        }

        return null;
    }

    /** Where a guide (or, with null, the list of guides) is read. */
    public static function url(?string $slug = null): string
    {
        if (Route::has('guides.index') && Edition::isSaas()) {
            return $slug !== null ? route('guides.show', $slug) : route('guides.index');
        }

        return rtrim((string) config('guides.site_url'), '/').'/guides'.($slug !== null ? '/'.$slug : '');
    }

    /**
     * The screen being shown. Inside a Livewire update the request is
     * Livewire's own, so the page's route is found again from its address.
     */
    public static function currentRouteName(): ?string
    {
        $request = request();
        if (! \Livewire\Livewire::isLivewireRequest()) {
            return $request->route()?->getName();
        }

        try {
            return Route::getRoutes()->match(\Illuminate\Http\Request::create((string) \Livewire\Livewire::originalUrl(), 'GET'))->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The "Help" link for a screen.
     *
     * @return array{url:string,label:string,slug:string|null}
     */
    public static function linkFor(?string $routeName): array
    {
        $slug = self::forRoute($routeName);
        $guide = $slug !== null ? self::find($slug) : null;

        return [
            'url' => self::url($slug),
            'label' => $guide !== null ? (string) $guide['h1'] : 'Guides for running a clinic',
            'slug' => $slug,
        ];
    }
}
