<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What every page in this system is held to, using the visit module as the
 * model it was all rebuilt around (docs/visits.md).
 *
 * These are static rules over the sources rather than assertions about one
 * page's output, because the failure they exist to stop is DRIFT: a new index
 * written by copying an old one, a dropdown added in a hurry, an empty table
 * that renders as a blank rectangle. A sweep fixes those once; a rule keeps
 * them fixed.
 */
class PageStandardTest extends TestCase
{
    /**
     * Pages exempt from the table rules, and why. Each is a screen rather than
     * a list — nothing here paginates anything.
     */
    private const NOT_A_TABLE = [
        'Dashboard/Index.php' => 'a cockpit of widgets',
        'Onboarding/Index.php' => 'a setup checklist',
        'Reports/Index.php' => 'a set of charts over a date range',
        'Subscription/Index.php' => 'one subscription, not a list of them',
        'Super/Traffic/Index.php' => 'campaign reports over a date range; its one list is capped',
        'Patients/Form.php' => 'a form',
        'Settings/Billing.php' => 'a settings form',
        'Settings/Hospital.php' => 'a settings form',
        'Settings/Site.php' => 'a settings form',
    ];

    /**
     * Tables a model can grow without limit. A `<select>` over one of these
     * loads the whole table on every render and is unusable by the time it
     * matters, which is exactly why <livewire:ui.select-search> exists.
     *
     * Configuration lists a hospital sets up once and keeps small — its
     * departments, wards, stock categories, insurers — are NOT here: for six
     * options a `<select>` is the faster control, and pretending otherwise is
     * ceremony.
     */
    private const UNBOUNDED = ['doctors', 'users', 'heads', 'patients', 'rooms', 'staff', 'nurses'];

    /** @return list<string> every Livewire page component */
    private function pages(): array
    {
        $files = [];

        foreach (File::allFiles(app_path('Livewire')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // Children (pickers, panels, dashboard sections) are not pages.
            if (Str::contains($file->getPathname(), ['/Panels/', '/Ui/', '/Concerns/', '/Dashboard/', '/Shell/'])) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(app_path('Livewire').'/', '', $path);
    }

    // ── Tables ───────────────────────────────────────────────────────────

    /**
     * A list paginates, searches and remembers where it was in the URL.
     *
     * WithTable is what gives all three, plus the guards that stop a hand-built
     * table shipping an unbounded per-page or an unwhitelisted sort column.
     */
    public function test_every_listing_page_uses_the_shared_table_behaviour(): void
    {
        $offenders = [];

        foreach ($this->pages() as $path) {
            $name = $this->relative($path);

            if (! Str::endsWith($name, 'Index.php') || isset(self::NOT_A_TABLE[$name])) {
                continue;
            }
            if (Str::contains(File::get($path), 'WithTable')) {
                continue;
            }

            $offenders[] = $name;
        }

        $this->assertSame([], $offenders, "Listing pages not using WithTable:\n".implode("\n", $offenders));
    }

    /**
     * An empty table is a designed state, not a blank rectangle.
     *
     * `<x-ui.empty>` also knows the difference between "nothing here yet" and
     * "nothing matches your filters", which is the difference between a new
     * hospital and a mistyped search.
     */
    public function test_every_listing_view_has_a_designed_empty_state(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views/livewire')) as $file) {
            if ($file->getFilename() !== 'index.blade.php') {
                continue;
            }

            $blade = File::get($file->getPathname());

            // Only views that actually draw a table.
            if (! Str::contains($blade, '<tbody')) {
                continue;
            }
            // `x-dash.empty` is the same designed state in the dashboard's own
            // voice, for the pages built out of <x-dash.section>.
            if (Str::contains($blade, ['x-ui.empty', 'x-dash.empty'])) {
                continue;
            }

            $offenders[] = str_replace(resource_path('views/livewire').'/', '', $file->getPathname());
        }

        $this->assertSame([], $offenders, "Tables with no designed empty state:\n".implode("\n", $offenders));
    }

    // ── Dropdowns ────────────────────────────────────────────────────────

    /**
     * No `<select>` renders a table that grows.
     *
     * Every one of these was an uncapped `->get()` running on every render of
     * the page — the whole users table fetched to draw one dropdown — and by
     * the time a hospital has sixty doctors the control is useless anyway.
     */
    public function test_no_dropdown_renders_an_unbounded_table(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views/livewire')) as $file) {
            $blade = File::get($file->getPathname());

            preg_match_all('/<select\b[^>]*>(.*?)<\/select>/s', $blade, $matches, PREG_SET_ORDER);

            foreach ($matches as $select) {
                if (! preg_match('/@foreach\s*\(\$(?:this->)?([a-zA-Z_]+)/', $select[1], $loop)) {
                    continue;
                }
                if (! in_array(strtolower($loop[1]), self::UNBOUNDED, true)) {
                    continue;
                }

                $offenders[] = str_replace(resource_path('views/'), '', $file->getPathname()).': $'.$loop[1];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Dropdowns over a table that grows — use <livewire:ui.select-search>:\n".implode("\n", $offenders),
        );
    }

    /**
     * …and nothing fetches one of those tables whole, either.
     *
     * The dropdown is only the symptom; the query behind it is the cost, and it
     * survives being moved into a variable.
     */
    public function test_no_page_fetches_a_whole_growing_table_for_a_dropdown(): void
    {
        $offenders = [];

        foreach ($this->pages() as $path) {
            // Comments describing the old queries are not the old queries.
            $php = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', File::get($path)) ?? '';

            // `User::currentHospital()->…->get()` and `Room::…->get()` with
            // nothing between them that bounds the result.
            preg_match_all('/(?:User|Room|Patient)::[^;]*?->get\(/s', $php, $matches);

            foreach ($matches[0] as $query) {
                if (Str::contains($query, ['limit(', 'take(', 'paginate(', 'whereKey(', 'find('])) {
                    continue;
                }

                $offenders[] = $this->relative($path);
                break;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Pages loading a whole growing table into memory:\n".implode("\n", $offenders),
        );
    }

    // ── Blade that silently is not Blade ─────────────────────────────────

    /**
     * No directive is glued to the word before it.
     *
     * Blade only reads `@…` as a directive when the character before it is not
     * a word character. `Edit@else New @endif` therefore compiles to an `if`
     * with the literal text "@else" inside it and NO else branch — so the word
     * "New" never reached the page, and nothing anywhere said so. It cost this
     * page a button that rendered as an empty box, and the patient form the
     * word "New" in its breadcrumb.
     *
     * A grep is the whole check, and it is cheap enough to run on every build.
     */
    public function test_no_blade_directive_is_swallowed_by_the_word_before_it(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! Str::endsWith($file->getFilename(), '.blade.php')) {
                continue;
            }

            // A Blade comment describing the trap is not the trap. Comments
            // are blanked rather than dropped so the line numbers still point
            // at the offending line.
            $blade = preg_replace_callback(
                '/\{\{--.*?--\}\}/s',
                fn (array $m) => str_repeat("\n", substr_count($m[0], "\n")),
                File::get($file->getPathname()),
            ) ?? '';

            foreach (explode("\n", $blade) as $number => $line) {
                // Openers as well as closers. A swallowed `@endif` leaves a
                // stray word in the page; a swallowed `@if` leaves its `@endif`
                // to be read on its own, and the whole file stops compiling.
                if (! preg_match('/\w@(if|unless|can|cannot|canany|foreach|forelse|for|while|php|isset|empty|auth|guest|switch|once|error|else|elseif|endif|endcan|endcannot|endcanany|endforeach|endforelse|endunless|endwhile|endfor|endphp|endsection|endisset|endempty|endauth|endguest|endswitch|endonce|enderror)\b/', $line, $m)) {
                    continue;
                }

                $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname())
                    .':'.($number + 1).' — @'.$m[1];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Directives Blade will treat as plain text (put a space or newline before `@`):\n".implode("\n", $offenders),
        );
    }

    // ── Dialogs ──────────────────────────────────────────────────────────

    /**
     * Money and state changes are pessimistic: the button disables itself while
     * the round-trip is in flight (house rule 12).
     *
     * Without it a double-click posts twice, and the second one is a second
     * payment, a second invoice or a second admission.
     */
    public function test_every_dialog_submit_disables_itself_while_it_works(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views/livewire')) as $file) {
            // Matched on a copy where `=>` has been neutralised. `<button\b[^>]*>`
            // stops at the FIRST `>`, and a button whose classes are built with
            // an array — `@class(['a', 'b' => $c])` — supplies one inside its
            // own tag, so every attribute after it was invisible here. The same
            // trap SpaNavigationHtmlTest documents; it was still set in this one.
            $blade = str_replace('=>', '= ', File::get($file->getPathname()));

            preg_match_all('/<button\b[^>]*type="submit"[^>]*>/s', $blade, $matches);

            foreach ($matches[0] as $button) {
                // A Livewire action disables with wire:loading; a plain form
                // (a sign-out, which must leave the SPA) with Alpine.
                if (Str::contains($button, ['wire:loading.attr="disabled"', ':disabled="busy"'])) {
                    continue;
                }

                $offenders[] = str_replace(resource_path('views/'), '', $file->getPathname());
                break;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Submit buttons that can be pressed twice:\n".implode("\n", $offenders),
        );
    }
}
