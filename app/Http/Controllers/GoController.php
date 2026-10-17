<?php

namespace App\Http\Controllers;

use App\Services\TrafficRecorder;
use App\Support\Edition;
use App\Support\SupportContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/go/whatsapp`: the public site's WhatsApp buttons, counted on the way out.
 *
 * The tap is written on the visitor's trail (TrafficRecorder: `whatsapp_at`
 * and a `whatsapp` event, shown in the funnel on /super/traffic), then the
 * visitor goes straight on to wa.me with the page's prefilled message. The
 * destination is always built here from the configured number, never read
 * from the request, so this cannot be used as an open redirect. With no
 * WhatsApp line configured, the contact page answers instead.
 */
class GoController extends Controller
{
    public function whatsapp(Request $request, TrafficRecorder $traffic): RedirectResponse
    {
        $context = (string) $request->query('c', 'site.general');
        if (! self::tracks($context) || ! array_key_exists($context, (array) trans('support'))) {
            $context = 'site.general';
        }

        $vars = self::vars((string) $request->query('p', ''));
        if ($context === 'site.page' && $vars === []) {
            $context = 'site.general'; // "your page about" what, otherwise
        }

        $link = SupportContact::current()->waLink($context, $vars);
        if ($link === '') {
            return redirect()->route('contact');
        }

        $traffic->action($request, 'whatsapp');

        return redirect()->away($link)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Where a WhatsApp button on this page should point: through here on the
     * public site of the online service (so the tap is counted), straight to
     * wa.me everywhere else (inside the panel, on a hospital's own server).
     *
     * @param  array<string,scalar|null>  $vars
     */
    public static function whatsappUrl(string $context, array $vars = [], ?SupportContact $contact = null): string
    {
        $contact ??= SupportContact::current();
        $direct = $contact->waLink($context, $vars);

        if ($direct === '' || ! self::tracks($context) || ! Edition::isSaas()) {
            return $direct;
        }

        return route('go.whatsapp', array_filter(['c' => $context, 'p' => isset($vars['page']) ? (string) $vars['page'] : null]));
    }

    /** Only the public site's own messages are counted. */
    private static function tracks(string $context): bool
    {
        return str_starts_with($context, 'site.');
    }

    /** The page name, as plain words: it ends up inside a prefilled message. @return array<string,string> */
    private static function vars(string $page): array
    {
        $page = trim((string) preg_replace('/[^\pL\pN \-\'&,.]/u', '', $page));
        $page = mb_substr($page, 0, 60);

        return $page === '' ? [] : ['page' => $page];
    }
}
