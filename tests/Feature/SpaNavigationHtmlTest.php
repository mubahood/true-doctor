<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * House rules 1–2 (docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md Part V): every in-app
 * link is wire:navigate and no admin view performs a hard navigation or a
 * native dialog. Static regression guard over the Blade sources.
 */
class SpaNavigationHtmlTest extends TestCase
{
    /**
     * Routes that legitimately leave the SPA without opening a tab: a file the
     * browser saves rather than shows, and the hand-offs that end the session
     * or leave the site.
     */
    private const ALLOWED_PLAIN_ROUTES = [
        'admin.patients.documents.download', 'admin.orders.attachments.download',
        'admin.logout', 'gateway.callback', 'gateway.webhook',
        // Field Mode is a SEPARATE application, not a Livewire page. A
        // `wire:navigate` to it would try to swap a client-rendered shell
        // into the Livewire SPA and leave neither working.
        'field',
    ];

    /**
     * Documents this system GENERATES — an invoice, a receipt, a lab report.
     *
     * Every one of them is read before it is kept, so every one of them opens
     * in its own tab: the controller streams it inline and the link carries
     * `target="_blank"`. Sending somebody's browser straight to a download for
     * a document they only wanted to look at is how a folder fills up with
     * `receipt (3).pdf`.
     *
     * Uploaded files are deliberately NOT here. They are whatever somebody
     * attached, and serving arbitrary uploads inline on our own origin is an
     * XSS vector; those stay downloads.
     */
    private const GENERATED_DOCUMENT_ROUTES = [
        'admin.invoices.pdf', 'admin.payments.receipt', 'admin.patients.id-card',
        'admin.lab-orders.pdf', 'admin.radiology-orders.pdf', 'admin.admissions.summary',
        'admin.insurance-providers.usage', 'admin.visits.report',
        'admin.reports.pdf',
    ];

    /**
     * The only classic POST form a Livewire view may still contain: a payment
     * gateway hand-off, which has to leave the SPA with a real form + CSRF
     * (plan §4.4 "gateway button stays a real POST").
     */
    private const ALLOWED_POST_FORM_ROUTES = [
        'admin.invoices.flutterwave',      // hosted payment page hand-off
        'admin.subscription.checkout',     // hosted payment page hand-off
        'demo.leave',                      // signs out: invalidates the session and CSRF token, so it must be a full page load
    ];

    /**
     * Every `<a …>` in a view, with the route name it points at.
     *
     * Tags are matched on a copy where `=>` has been neutralised. The obvious
     * `<a\b[^>]*>` stops at the FIRST `>`, and a link built with an array —
     * `route('x', [$a, 'from' => $b])` — supplies one inside its own href, so
     * every attribute after it was invisible to this file. A link could
     * therefore carry `target="_blank"` and still be reported as missing it.
     *
     * @return list<array{0:string,1:string}> [tag, route name]
     */
    private function routeLinksIn(string $file): array
    {
        $html = str_replace('=>', '= ', File::get($file));

        preg_match_all('/<a\b[^>]*>/s', $html, $tags);

        $links = [];
        foreach ($tags[0] as $tag) {
            if (preg_match("/href=\"\{\{ route\('([^']+)'/", $tag, $m)) {
                $links[] = [$tag, $m[1]];
            }
        }

        return $links;
    }

    /** @return list<string> */
    private function adminViews(): array
    {
        $files = [];
        // 'super' held the classic super-admin pages; the panel is Livewire-only
        // since Phase 2, so the directory is allowed to be gone.
        foreach (['admin', 'super', 'livewire', 'partials', 'components/ui', 'components/dash'] as $dir) {
            if (! File::isDirectory(resource_path("views/$dir"))) {
                continue;
            }
            foreach (File::allFiles(resource_path("views/$dir")) as $f) {
                $files[] = $f->getPathname();
            }
        }
        $files[] = resource_path('views/layouts/admin.blade.php');

        return $files;
    }

    public function test_every_in_app_route_link_uses_wire_navigate(): void
    {
        $offenders = [];

        foreach ($this->adminViews() as $file) {
            foreach ($this->routeLinksIn($file) as [$tag, $route]) {
                if (str_contains($tag, 'wire:navigate') || str_contains($tag, 'target="_blank"')) {
                    continue;
                }
                if (in_array($route, self::ALLOWED_PLAIN_ROUTES, true)) {
                    continue;
                }

                $offenders[] = str_replace(resource_path('views/'), '', $file).': '.$route;
            }
        }

        $this->assertSame([], $offenders, "Links without wire:navigate:\n".implode("\n", $offenders));
    }

    /**
     * A generated document opens in a new tab rather than downloading on the
     * spot. Two halves, both asserted: the link says `target="_blank"`, and the
     * controller behind it streams the PDF inline instead of attaching it.
     */
    public function test_every_generated_document_opens_in_a_new_tab(): void
    {
        $offenders = [];

        foreach ($this->adminViews() as $file) {
            foreach ($this->routeLinksIn($file) as [$tag, $route]) {
                if (! in_array($route, self::GENERATED_DOCUMENT_ROUTES, true)) {
                    continue;
                }
                if (str_contains($tag, 'target="_blank"')) {
                    continue;
                }

                $offenders[] = str_replace(resource_path('views/'), '', $file).': '.$route;
            }
        }

        $this->assertSame([], $offenders, "Documents that download instead of opening:\n".implode("\n", $offenders));
    }

    /** …and the same rule for the <x-ui.link> form of the same thing. */
    public function test_no_controller_forces_a_generated_document_to_download(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            $php = File::get($file->getPathname());

            // `$disk->download()` is a stored upload, which stays a download.
            if (preg_match('/Pdf::loadView/', $php) && preg_match('/->download\(/', $php)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, "Controllers attaching a generated PDF:\n".implode("\n", $offenders));
    }

    /**
     * The one sanctioned classic POST left inside a Livewire view: subscription
     * checkout ends in the external Flutterwave redirect (plan §3.1).
     */
    public function test_no_hard_navigation_or_native_dialogs_in_livewire_views(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views/livewire')) as $f) {
            $html = File::get($f->getPathname());
            foreach (['window.location', 'location.reload', 'alert(', 'confirm(', 'onclick="'] as $needle) {
                if (str_contains($html, $needle)) {
                    $offenders[] = $f->getRelativePathname().': '.$needle;
                }
            }

            // Every mutation is a Livewire action; only a gateway hand-off may POST.
            preg_match_all('/<form\b[^>]*method="post"[^>]*>/i', $html, $forms);
            foreach ($forms[0] as $form) {
                if (preg_match("/action=\"\{\{ route\('([^']+)'/", $form, $m)
                    && in_array($m[1], self::ALLOWED_POST_FORM_ROUTES, true)) {
                    continue;
                }
                $offenders[] = $f->getRelativePathname().': method="POST"';
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }
}
