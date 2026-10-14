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

    public function products(): \Illuminate\Contracts\View\View
    {
        return view('marketing.standalone', [
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

    public function callback(Request $request): RedirectResponse
    {
        $tracking = (string) $request->query('OrderTrackingId', '');
        if ($tracking === '') {
            return redirect()->route('standalone.products')->with('error', 'The payment gateway did not return a tracking ID.');
        }
        $result = $this->sales->settle($tracking);
        if (! in_array($result, ['settled', 'already_settled'], true)) {
            return redirect()->route('standalone.products')->with('error', 'Payment is not confirmed yet. Reference: '.$result);
        }
        $order = \App\Models\StandaloneOrder::where('gateway_tracking_id', $tracking)->first();
        if ($order === null || $order->licence_id === null) {
            return redirect()->route('standalone.products')->with('error', 'Payment was confirmed but the download is not ready. Contact support with your receipt.');
        }
        $request->session()->regenerate();
        $request->session()->put('standalone_licence_id', $order->licence_id);

        return redirect()->route('standalone.portal')->with('status', 'Payment confirmed. Your downloads are ready.');
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
        $request->session()->regenerate();
        $request->session()->put('standalone_licence_id', $licence->id);

        return redirect()->route('standalone.portal');
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

    private function sessionLicence(Request $request): ?StandaloneLicence
    {
        $id = $request->session()->get('standalone_licence_id');

        return is_string($id) ? StandaloneLicence::whereKey($id)->where('status', 'active')->first() : null;
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
