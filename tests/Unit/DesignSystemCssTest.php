<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The stylesheet is hand-written and the suite runs with @vite disabled, so two
 * failures used to reach the browser unnoticed: a class in a view that nobody
 * ever styled, and a stylesheet edit that was never rebuilt. Both render as
 * unstyled markup — default serif-ish sizing, no spacing — while every other
 * test passes. This file is the guard for both.
 */
class DesignSystemCssTest extends TestCase
{
    /** House prefixes: the admin design system (tb-) and the auth layer. */
    private const CLASS_PATTERN = '/^(?:tb|a|auth|reg)-[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function test_every_design_system_class_used_in_a_view_is_defined_in_the_css(): void
    {
        $defined = $this->definedClasses();
        $undefined = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $html = File::get($file->getPathname());

            preg_match_all('/class="([^"]*)"/', $html, $attributes);

            foreach ($attributes[1] as $attribute) {
                // Interpolated values are runtime data, not literal class names.
                $attribute = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', ' ', $attribute) ?? '';

                foreach (preg_split('/\s+/', $attribute, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                    if (preg_match(self::CLASS_PATTERN, $class) && ! in_array($class, $defined, true)) {
                        $undefined[] = $file->getRelativePathname().' → .'.$class;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($undefined)),
            "Views use design-system classes that no stylesheet defines (they render unstyled):\n"
            .implode("\n", array_unique($undefined)));
    }

    public function test_the_built_assets_are_not_older_than_the_stylesheets_they_are_built_from(): void
    {
        if (! File::exists(public_path('build/manifest.json'))) {
            $this->markTestSkipped('No local Vite build — CI builds the assets in its own step.');
        }

        $builtAt = 0;
        foreach (File::allFiles(public_path('build')) as $asset) {
            $builtAt = max($builtAt, $asset->getMTime());
        }

        $stale = [];
        foreach ([resource_path('css'), resource_path('js')] as $directory) {
            foreach (File::allFiles($directory) as $source) {
                if ($source->getMTime() > $builtAt) {
                    $stale[] = 'resources/'.basename($directory).'/'.$source->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $stale,
            "Front-end sources are newer than public/build — the browser is still being served the old\n"
            ."bundle. Run `npm run build`:\n".implode("\n", $stale));
    }

    /**
     * .badge-tb is the passive status-pill vocabulary (x-ui.badge always renders
     * a <span>) — nothing in the stylesheet resets a <button>'s native chrome
     * for it (border, background, cursor, appearance), unlike every real
     * button class here (.wz-step-head, .wz-mini-item, .wz-chip), which resets
     * one explicitly. Putting badge-tb on a <button> renders the browser's
     * default button skin underneath the badge colours — lumpy borders,
     * inconsistent padding — exactly once did, in the onboarding wizard's
     * "still needed" row (fixed by giving it its own .wz-chip).
     */
    /**
     * A component whose parent class is used without its children.
     *
     * `.tb-seg` is a flex row; all the LOOK is on `.tb-seg-btn`. Field Mode's
     * tab strip carried the container and nothing on the buttons, so seven
     * raw `<button>` elements rendered jammed together in browser default
     * styling, on a screen a clinician is supposed to work from.
     *
     * The sibling test above cannot see this: `tb-seg` IS defined, so every
     * class used was accounted for. What was missing was the other half of a
     * pair, which is a different question and needs asking separately.
     */
    public function test_a_component_is_never_used_without_the_classes_that_style_it(): void
    {
        /*
         * ONE pair, deliberately.
         *
         * The first draft also claimed `.tb-table` needs `.tb-table-wrap` and
         * `.tb-toolbar` needs `.tb-search-wrap`, and flagged six files that
         * were perfectly correct: a partial is wrapped by whatever includes
         * it, and a toolbar is allowed to have no search box. A guard that
         * cries wolf is a guard somebody deletes, so it asserts only the rule
         * that genuinely holds — `.tb-seg` is a bare flex row and every
         * scrap of its appearance lives on `.tb-seg-btn`.
         */
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $html = File::get($file->getPathname());

            // `(?![-\w])` so `tb-seg-cards` — a different component — is not
            // mistaken for a segmented control missing its buttons.
            if (preg_match('/class="[^"]*\btb-seg(?![-\w])/', $html) && ! str_contains($html, 'tb-seg-btn')) {
                $offenders[] = str_replace(resource_path('views/'), '', $file->getPathname())
                    .': .tb-seg without .tb-seg-btn';
            }
        }

        $this->assertSame([], $offenders,
            "Components used without the class that gives them their look:\n".implode("\n", $offenders));
    }

    public function test_the_passive_badge_class_is_never_used_on_an_interactive_button(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $html = File::get($file->getPathname());

            preg_match_all('/<button\b[^>]*class="([^"]*)"[^>]*>/i', $html, $buttons);

            foreach ($buttons[1] as $classAttr) {
                if (preg_match('/\bbadge-tb\b/', $classAttr)) {
                    $offenders[] = $file->getRelativePathname().' → <button class="'.$classAttr.'">';
                }
            }
        }

        $this->assertSame([], $offenders,
            "badge-tb is a passive <span> pill with no button reset behind it — using it on a <button>\n"
            ."renders the browser's default button chrome underneath the badge colours. Give the button its\n"
            ."own class (reset border/background/cursor explicitly) instead:\n".implode("\n", $offenders));
    }

    /** @return list<string> every class selector any stylesheet defines */
    private function definedClasses(): array
    {
        $classes = [];

        foreach (File::files(resource_path('css')) as $stylesheet) {
            preg_match_all('/\.([a-zA-Z][\w-]*)/', File::get($stylesheet->getPathname()), $matches);
            $classes = array_merge($classes, $matches[1]);
        }

        return array_values(array_unique($classes));
    }

    /**
     * Spacing does not stack.
     *
     * The new-visit form used to put the modal body's own 14px gap, a 20px
     * margin, 16px of padding AND a rule between every section — fifty pixels
     * and a line, three times down a form that then needed scrolling to reach
     * its own submit button. The fix was to let ONE thing set the rhythm, and
     * the way it comes back is somebody adding a margin "just here".
     */
    public function test_a_grouped_form_lets_one_rule_set_its_spacing(): void
    {
        $css = File::get(resource_path('css/admin.css'));

        preg_match('/\.tb-vform-step\{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m, '.tb-vform-step is gone — this guard needs rewriting');

        $this->assertStringNotContainsString('border-top', $m[1], 'the section rules are back');
        $this->assertStringNotContainsString('padding-top', $m[1], 'padding is stacking against the gap again');
        $this->assertStringContainsString('gap:', $m[1], 'nothing is setting the rhythm');

        // And nothing inside a group carries a margin to stack with it.
        preg_match('/\.tb-vform-step > \.tb-form-group\{([^}]*)\}/', $css, $inner);
        $this->assertNotEmpty($inner, 'fields inside a group are free to add their own margins again');
        $this->assertStringContainsString('margin:0', str_replace(' ', '', $inner[1]));
    }
}
