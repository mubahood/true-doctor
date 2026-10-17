<?php

namespace App\Http\Controllers;

use App\Models\StandaloneLicence;
use App\Models\StandaloneProduct;
use App\Models\StandaloneRelease;
use App\Services\StandaloneSalesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StandalonePurchaseController extends Controller
{
    public function __construct(private readonly StandaloneSalesService $sales) {}

    public function products(Request $request): \Illuminate\Contracts\View\View
    {
        $order = $this->orderInBrowser($request);

        return view('marketing.standalone', [
            'resumeOrder' => $order !== null && $order->status !== 'paid' ? $order : null,
            'resumeDraft' => $this->draft($request),
            'owned' => self::licenceInSession($request),
            'products' => StandaloneProduct::where('is_active', true)->orderBy('price_ugx')->get(),
            'available' => $this->sales->open(),
            'latest' => StandaloneRelease::where('status', 'published')->orderByDesc('published_at')->first(),
        ]);
    }

    public function checkout(Request $request, StandaloneProduct $product): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
        ]);
        try {
            [, $charge] = $this->sales->start($product, trim($data['name']), $data['email'], trim($data['domain']));
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }
        if (! $charge->ok || $charge->link === null) {
            return back()->with('error', $charge->error ?? 'Payment could not be started.')->withInput();
        }

        return redirect()->away($charge->link);
    }

    /** Cookie names: what the buyer typed, the order they are paying, the licence they own. */
    public const DRAFT = 'td_buy_draft';

    public const ORDER = 'td_buy_order';

    public const LICENCE = 'td_licence';

    /** Step 2: who is buying and where it will run, kept as they type it. */
    public function details(Request $request, StandaloneProduct $product): \Illuminate\Contracts\View\View|RedirectResponse
    {
        if (! $product->is_active || ! $this->sales->open()) {
            return redirect()->route('standalone.products');
        }

        return view('marketing.standalone-buy', ['step' => 2, 'product' => $product, 'draft' => $this->draft($request)]);
    }

    public function saveDetails(Request $request, StandaloneProduct $product): RedirectResponse
    {
        abort_unless($product->is_active, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
        ], ['domain.regex' => 'Enter the address only, like hms.yourhospital.com, without https:// or a slash.']);
        $draft = ['product_id' => $product->id, 'name' => trim($data['name']), 'email' => mb_strtolower(trim($data['email'])),
            'phone' => trim((string) ($data['phone'] ?? '')), 'domain' => mb_strtolower(trim($data['domain']))];
        $request->session()->put(self::DRAFT, $draft);

        return redirect()->route('standalone.review')->withCookie(cookie(self::DRAFT, json_encode($draft), 60 * 24 * 30));
    }

    /** Step 3: check it, then pay. */
    public function review(Request $request): \Illuminate\Contracts\View\View|RedirectResponse
    {
        $draft = $this->draft($request);
        $product = $draft ? StandaloneProduct::where('is_active', true)->find($draft['product_id'] ?? 0) : null;
        if ($product === null || blank($draft['email'] ?? null)) {
            return redirect()->route('standalone.products');
        }

        return view('marketing.standalone-buy', ['step' => 3, 'product' => $product, 'draft' => $draft]);
    }

    public function pay(Request $request): RedirectResponse
    {
        $draft = $this->draft($request);
        $product = $draft ? StandaloneProduct::where('is_active', true)->find($draft['product_id'] ?? 0) : null;
        if ($product === null || blank($draft['email'] ?? null)) {
            return redirect()->route('standalone.products');
        }
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Tick the box to confirm the terms.']);
        try {
            [$order, $charge] = $this->sales->start($product, $draft['name'], $draft['email'], $draft['domain']);
        } catch (\DomainException $exception) {
            return redirect()->route('standalone.review')->with('error', $exception->getMessage());
        }
        $request->session()->put(self::ORDER, $order->reference);
        $remember = cookie(self::ORDER, $order->reference, 60 * 24 * 30);
        if (! $charge->ok || $charge->link === null) {
            \Illuminate\Support\Facades\Log::warning('Standalone payment did not start', ['order' => $order->reference, 'error' => $charge->error]);

            return redirect()->route('standalone.review')->withCookie($remember)->with('error',
                'We could not open the payment page just now ('.($charge->error ?? 'no answer').'). Your details are saved. Try again in a minute, or WhatsApp us and we will help you pay by mobile money.');
        }

        return redirect()->away($charge->link)->withCookie($remember);
    }

    /** Step 4 when the payment is not confirmed yet: the order this browser is paying, and a button to ask again. */
    public function status(Request $request): \Illuminate\Contracts\View\View|RedirectResponse
    {
        $order = $this->orderInBrowser($request);
        if ($order === null) {
            return redirect()->route('standalone.products');
        }
        if ($order->status === 'paid' && $order->licence_id !== null) {
            return $this->toDownloads($request, (string) $order->licence_id);
        }

        return view('marketing.standalone-buy', ['step' => 4, 'order' => $order, 'product' => $order->product, 'draft' => $this->draft($request)]);
    }

    public function checkStatus(Request $request): RedirectResponse
    {
        $order = $this->orderInBrowser($request);
        if ($order === null) {
            return redirect()->route('standalone.products');
        }
        $result = $this->sales->check($order);
        $order->refresh();
        if (in_array($result, ['settled', 'already_settled'], true) && $order->licence_id !== null) {
            return $this->toDownloads($request, (string) $order->licence_id, 'Payment confirmed. Your downloads are ready.');
        }

        return redirect()->route('standalone.status')->with('status', match ($result) {
            'not_started' => 'No payment was made for this order. Go back and pay when you are ready.',
            'not_successful' => 'The payment service says this payment did not go through. You can try again; you have not been charged twice.',
            default => 'Not confirmed yet. Mobile money can take a few minutes. Check again shortly; we also email you the moment it is confirmed.',
        });
    }

    public function callback(Request $request): RedirectResponse
    {
        $tracking = (string) $request->query('OrderTrackingId', '');
        if ($tracking === '') {
            return redirect()->route('standalone.products')->with('error', 'The payment gateway did not return a tracking ID.');
        }
        $result = $this->sales->settle($tracking);
        if (! in_array($result, ['settled', 'already_settled'], true)) {
            $pending = \App\Models\StandaloneOrder::where('gateway_tracking_id', $tracking)->first();
            if ($pending !== null) {
                $request->session()->put(self::ORDER, $pending->reference);

                return redirect()->route('standalone.status')->withCookie(cookie(self::ORDER, $pending->reference, 60 * 24 * 30));
            }

            return redirect()->route('standalone.products')->with('error', 'Payment is not confirmed yet. Reference: '.$result);
        }
        $order = \App\Models\StandaloneOrder::where('gateway_tracking_id', $tracking)->first();
        if ($order === null || $order->licence_id === null) {
            return redirect()->route('standalone.products')->with('error', 'Payment was confirmed but the download is not ready. Contact support with your receipt.');
        }

        return $this->toDownloads($request, (string) $order->licence_id, 'Payment confirmed. Your downloads are ready.');
    }

    public function portal(Request $request): \Illuminate\Contracts\View\View
    {
        $licence = $this->sessionLicence($request);
        $release = $licence ? $this->latestEntitled($licence) : null;

        return view('marketing.standalone-portal', [
            'licence' => $licence,
            'release' => $release,
            'earlier' => $licence ? $this->entitled($licence)->where('id', '!=', $release?->id)->limit(5)->get() : collect(),
            'guide' => $licence ? $this->guideHtml() : null,
            'renewal' => $licence?->product?->renewal_price_ugx !== null && $this->sales->open() ? $licence->product : null,
            'payments' => $licence ? \App\Models\StandaloneOrder::where('licence_id', $licence->id)->where('status', 'paid')->orderBy('paid_at')->get() : collect(),
        ]);
    }

    public function requestLink(Request $request): RedirectResponse
    {
        $email = Str::lower((string) $request->validate(['email' => ['required', 'email', 'max:190']])['email']);
        foreach (StandaloneLicence::where('customer_email', $email)->where('status', 'active')->get() as $licence) {
            $this->sales->sendLink($licence);
        }

        // The same answer either way: the form must not reveal who has a licence.
        return back()->with('status', 'If this email has a licence, a private link is on its way.');
    }

    /** Buy another period of updates for the licence signed in. */
    public function renew(Request $request): RedirectResponse
    {
        $licence = $this->sessionLicence($request);
        abort_if($licence === null, 403);

        try {
            [, $charge] = $this->sales->startRenewal($licence);
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        if (! $charge->ok || $charge->link === null) {
            return back()->with('error', $charge->error ?? 'Payment could not be started.');
        }

        return redirect()->away($charge->link);
    }

    /** Move the licence to the address the buyer really installs on. */
    public function changeDomain(Request $request): RedirectResponse
    {
        $licence = $this->sessionLicence($request);
        abort_if($licence === null, 403);
        $domain = (string) $request->validate(['domain' => ['required', 'string', 'max:253']])['domain'];

        try {
            $this->sales->changeDomain($licence, $domain);
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'The licence now covers '.$licence->fresh()->domain().'. Download licence.json again and replace the old one.');
    }

    /** The installation guide as a page, beside the download (R9). */
    public function guide(Request $request): \Illuminate\Contracts\View\View
    {
        abort_if($this->sessionLicence($request) === null, 403);

        return view('marketing.standalone-guide', ['guide' => $this->guideHtml()]);
    }

    public function claim(Request $request, StandaloneLicence $licence): RedirectResponse
    {
        abort_unless($licence->status === 'active', 404);

        return $this->toDownloads($request, (string) $licence->id);
    }

    public function downloadLicence(Request $request): \Illuminate\Http\Response
    {
        $licence = $this->sessionLicence($request);
        abort_if($licence === null, 403);

        return response(json_encode($this->sales->document($licence), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="licence.json"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function downloadGuide(Request $request): BinaryFileResponse
    {
        abort_if($this->sessionLicence($request) === null, 403);

        return response()->download(resource_path('standalone/INSTALL.md'), 'INSTALL.md', ['Cache-Control' => 'no-store, private']);
    }

    public function downloadRelease(Request $request, StandaloneRelease $release): BinaryFileResponse
    {
        $licence = $this->sessionLicence($request);
        abort_if($licence === null || $release->status !== 'published'
            || $release->published_at === null
            || $release->published_at->greaterThan($licence->updates_until->endOfDay()), 403);
        abort_if(! is_string($release->archive_path) || ! is_file($release->archive_path)
            || ! hash_equals((string) $release->sha256, (string) hash_file('sha256', $release->archive_path)), 503);
        \Illuminate\Support\Facades\DB::table('standalone_downloads')->insert([
            'licence_id' => $licence->id, 'release_id' => $release->id,
            'ip' => $request->ip(), 'created_at' => now(),
        ]);

        return response()->download($release->archive_path, 'true-doctor-standalone-'.$release->version.'.zip', [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** A shared computer: forget the licence on this browser. */
    public function forget(Request $request): RedirectResponse
    {
        $request->session()->forget('standalone_licence_id');

        return redirect()->route('standalone.portal')->withCookie(cookie()->forget(self::LICENCE))
            ->with('status', 'This browser no longer opens your downloads. Your email link still works.');
    }

    /** Sign this browser in to a licence's downloads, and remember it on this device. */
    private function toDownloads(Request $request, string $licenceId, ?string $status = null): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->put('standalone_licence_id', $licenceId);
        $request->session()->forget([self::DRAFT, self::ORDER]);
        $to = redirect()->route('standalone.portal')
            ->withCookie(cookie(self::LICENCE, $licenceId, 60 * 24 * 90))
            ->withCookie(cookie()->forget(self::DRAFT))
            ->withCookie(cookie()->forget(self::ORDER));

        return $status !== null ? $to->with('status', $status) : $to;
    }

    /** @return array<string,mixed>|null what the buyer typed, from this session or this device */
    private function draft(Request $request): ?array
    {
        $draft = $request->session()->get(self::DRAFT);
        if (! is_array($draft)) {
            $draft = json_decode((string) $request->cookie(self::DRAFT, ''), true);
        }

        return is_array($draft) ? $draft : null;
    }

    private function orderInBrowser(Request $request): ?\App\Models\StandaloneOrder
    {
        $reference = $request->session()->get(self::ORDER) ?? $request->cookie(self::ORDER);

        return is_string($reference) && $reference !== '' ? \App\Models\StandaloneOrder::where('reference', $reference)->first() : null;
    }

    public static function licenceInSession(Request $request): ?StandaloneLicence
    {
        $id = $request->session()->get('standalone_licence_id') ?? $request->cookie(self::LICENCE);

        return is_string($id) ? StandaloneLicence::whereKey($id)->where('status', 'active')->first() : null;
    }

    private function sessionLicence(Request $request): ?StandaloneLicence
    {
        return self::licenceInSession($request);
    }

    private function latestEntitled(StandaloneLicence $licence): ?StandaloneRelease
    {
        return $this->entitled($licence)->first();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<StandaloneRelease> */
    private function entitled(StandaloneLicence $licence): \Illuminate\Database\Eloquent\Builder
    {
        return StandaloneRelease::where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $licence->updates_until->copy()->endOfDay())
            ->orderByDesc('published_at')->orderByDesc('id');
    }

    private function guideHtml(): string
    {
        return Str::markdown((string) file_get_contents(resource_path('standalone/INSTALL.md')), [
            'html_input' => 'escape', 'allow_unsafe_links' => false,
        ]);
    }
}
