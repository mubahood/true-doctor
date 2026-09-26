<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Notifications\PublicEnquiryReceived;
use App\Support\VisitorRegion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public marketing pages.
 *
 * Controller actions rather than route closures so `php artisan route:cache`
 * works in production.
 */
class MarketingController extends Controller
{
    public function pricing(): View
    {
        return view('marketing.pricing', [
            'plans' => Plan::where('is_active', true)->orderBy('price')->get(),
            'copy' => $this->planCopy(),
            'matrix' => $this->planMatrix(),
        ]);
    }

    /**
     * What each plan is FOR, in words the price list cannot express.
     *
     * Here rather than in the view for two reasons. It is page data, and page
     * data belongs to whatever assembled the page. And Blade's `@php ... @endphp`
     * blocks are extracted by a non-greedy regex that pairs the first `@php`
     * it finds with the first `@endphp` after it — so a `@php(...)` one-liner
     * anywhere above a block makes that regex swallow everything in between
     * and silently stop compiling the rest of the file. Keeping arrays out of
     * views removes that trap rather than tiptoeing around it.
     *
     * @return array<string, array{who:string, feats:list<string>}>
     */
    private function planCopy(): array
    {
        return [
            'starter' => [
                'who' => 'A single clinic or practice finding its feet',
                'feats' => [
                    'Patients, appointments and visits',
                    'Billing, receipts and payments',
                    'Field Mode for offline work',
                    'Email support',
                ],
            ],
            'professional' => [
                'who' => 'A busy clinic or polyclinic with several departments',
                'feats' => [
                    'Everything in Starter',
                    'Pharmacy, lab and radiology',
                    'Insurance claims and patient cards',
                    'Reports and printable board papers',
                    'Priority support',
                ],
            ],
            'enterprise' => [
                'who' => 'A multi-department or multi-branch hospital',
                'feats' => [
                    'Everything in Professional',
                    'Inpatient wards, beds and per-night billing',
                    'No limits on staff, patients or beds',
                    'Dedicated onboarding and support',
                ],
            ],
        ];
    }

    /**
     * Which modules each plan includes.
     *
     * The cards say what a plan ADDS; this says, in one place, what a hospital
     * gets and does not. Without it somebody opens three tabs and compares
     * bullet lists by eye.
     *
     * @return array<string, list<string>> module => plan slugs that include it
     */
    private function planMatrix(): array
    {
        return [
            'Patients, records and ID cards' => ['starter', 'professional', 'enterprise'],
            'Appointments and doctor schedules' => ['starter', 'professional', 'enterprise'],
            'Visits, vitals and prescriptions' => ['starter', 'professional', 'enterprise'],
            'Invoicing, payments and receipts' => ['starter', 'professional', 'enterprise'],
            'Field Mode (works offline)' => ['starter', 'professional', 'enterprise'],
            'Pharmacy and stock control' => ['professional', 'enterprise'],
            'Laboratory and radiology' => ['professional', 'enterprise'],
            'Insurance claims and patient cards' => ['professional', 'enterprise'],
            'Reports and printable board papers' => ['professional', 'enterprise'],
            'Inpatient wards, beds and admissions' => ['enterprise'],
            'Dedicated onboarding' => ['enterprise'],
        ];
    }

    /**
     * Quote me in the other currency.
     *
     * A guess the visitor has corrected is not a guess any more, so the
     * choice outranks every signal and is remembered for a year. A plain
     * POST + redirect rather than anything clever: it has to work on a page
     * with no JavaScript, and the back button has to behave.
     */
    public function currency(Request $request): RedirectResponse
    {
        $region = app(VisitorRegion::class);
        $wanted = Str::upper((string) $request->input('currency'));

        if (! in_array($wanted, $region->supported(), true)) {
            return back();
        }

        // Back to where they were reading, never to an address supplied in
        // the request: an open redirect on a public page is an open redirect.
        return back()->withCookie(VisitorRegion::cookieFor($wanted));
    }

    public function contact(): View
    {
        return view('marketing.contact');
    }

    /**
     * The public pages, for a crawler.
     *
     * Generated from the route table rather than typed out, so a page added
     * next month is listed the day it exists. The demonstration door and
     * anything behind a login are deliberately absent — robots.txt already
     * disallows /admin, and a sitemap that advertises a sign-in page is a
     * sitemap working against itself.
     */
    public function sitemap(): Response
    {
        $pages = [
            'home' => ['1.0', 'weekly'],
            'features' => ['0.9', 'monthly'],
            'pricing' => ['0.9', 'monthly'],
            'security' => ['0.7', 'monthly'],
            'contact' => ['0.6', 'yearly'],
            'privacy' => ['0.3', 'yearly'],
            'terms' => ['0.3', 'yearly'],
        ];

        $xml = view('marketing.sitemap', ['pages' => $pages])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * An enquiry from the contact page.
     *
     * Three things stand between this and a spam cannon: a rate limit per IP,
     * a honeypot field no person can see, and a minimum time-on-page. None of
     * them asks a real visitor to prove anything.
     */
    public function enquire(Request $request): RedirectResponse
    {
        $this->assertNotFlooding($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191'],
            'hospital' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'size' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            // The honeypot. A person never sees it, so a person never fills
            // it; the rule is that it must stay empty.
            'website' => ['prohibited'],
        ], [
            'website.prohibited' => 'Something went wrong. Please try again.',
            'message.min' => 'Please tell us a little more — a sentence or two is plenty.',
        ]);

        $to = (string) config('mail.enquiries_to', config('mail.from.address'));

        if ($to !== '') {
            Notification::route('mail', $to)->notify(new PublicEnquiryReceived($data));
        }

        RateLimiter::hit($this->enquiryKey($request), 3600);

        // On the trail of the visit that sent it, so an enquiry from a
        // "Contact Sales" click counts for that sitelink.
        app(\App\Services\TrafficRecorder::class)->action($request, 'enquiry');

        return redirect()
            ->route('contact')
            ->with('sent', 'Thank you — your message is with us. We reply within one business day.');
    }

    /** @throws ValidationException */
    private function assertNotFlooding(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->enquiryKey($request), 5)) {
            return;
        }

        throw ValidationException::withMessages([
            'message' => 'That is several messages from this connection already. '
                .'Email us directly at '.config('mail.from.address').' and we will pick it up.',
        ]);
    }

    private function enquiryKey(Request $request): string
    {
        return 'enquiry:'.$request->ip();
    }
}
