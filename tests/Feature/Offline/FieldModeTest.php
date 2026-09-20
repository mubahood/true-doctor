<?php

namespace Tests\Feature\Offline;

use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Field Mode's shell, and the service worker's rules.
 *
 * The worker rules are asserted against the file's source rather than by
 * running it, because there is no service-worker runtime in PHPUnit — but the
 * rules are the kind a future edit breaks silently, and `public/sw.js` in this
 * repo is a kill-switch left behind by exactly that happening in production.
 * A test that reads the source is worth more than no test at all, and it says
 * plainly what it is checking.
 */
class FieldModeTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();
    }

    // ── The shell ────────────────────────────────────────────────────────

    public function test_the_shell_renders_for_a_signed_in_clinician(): void
    {
        $this->actingAs($this->nurse)
            ->get(route('field'))
            ->assertOk()
            ->assertSee('Field Mode')
            ->assertSee('<meta name="td-hospital" content="'.$this->hospital->id.'">', false)
            ->assertSee('<meta name="td-user" content="'.$this->nurse->id.'">', false);
    }

    public function test_the_shell_carries_no_patient_data_and_no_csrf_token(): void
    {
        // A real patient, so the assertion is about DATA rather than about
        // field names — the client templates legitimately mention `patient_no`
        // because they render one from IndexedDB.
        $patient = \App\Models\Patient::factory()->create([
            'hospital_id' => $this->hospital->id,
            'first_name' => 'Unmistakable',
            'last_name' => 'Testname',
        ]);

        $html = $this->actingAs($this->nurse)->get(route('field'))->getContent();

        // The shell has to render from the service-worker cache with no server
        // involved, so it carries no records — and a stale CSRF token would be
        // worse than none (plan §14).
        $this->assertStringNotContainsString('csrf-token', $html);
        $this->assertStringNotContainsString('Unmistakable', $html);
        $this->assertStringNotContainsString($patient->patient_no, $html);
        $this->assertStringNotContainsString($patient->uuid, $html);
    }

    public function test_field_mode_needs_a_signed_in_user(): void
    {
        $this->get(route('field'))->assertRedirect();
    }

    public function test_every_workflow_that_is_built_has_a_way_in(): void
    {
        // A partial that is never included is a screen nobody can reach, and
        // the wiring is three separate edits — a tab button, an `x-show` pane
        // and an Alpine component — so it is easy to land two of the three.
        $html = $this->actingAs($this->nurse)->get(route('field'))->assertOk()->getContent();

        foreach (['Ward round', 'Bench', 'Notes', 'Find a patient', 'Register', 'Not yet sent', 'Decisions'] as $tab) {
            $this->assertStringContainsString($tab, $html, "Field Mode has no way into '{$tab}'.");
        }

        // And the panes behind them.
        foreach (['fieldBench', 'fieldNotes', 'fieldConflicts', 'fieldPatients', 'fieldWard'] as $component) {
            $this->assertStringContainsString($component, $html, "'{$component}' is not on the page.");
        }
    }

    public function test_the_shell_tells_somebody_who_has_been_signed_out(): void
    {
        // A session ends every day on every device. Nothing is lost when it
        // does, but the queue stops moving — and a queue that never drains with
        // no explanation is how a clinician decides the system is broken.
        $this->actingAs($this->nurse)
            ->get(route('field'))
            ->assertSee('You have been signed out')
            ->assertSee('Your work is still on this device and nothing has been lost.')
            ->assertSee('authExpired', false);
    }

    public function test_the_shell_says_plainly_what_cannot_be_done_offline(): void
    {
        // A clinician who cannot find billing should be told it needs a
        // connection, not left wondering where the screen went.
        $this->actingAs($this->nurse)
            ->get(route('field'))
            ->assertSee('These need a connection')
            ->assertSee('Billing, payments and cards');
    }

    public function test_the_shell_warns_about_what_a_device_copy_means(): void
    {
        $this->actingAs($this->nurse)
            ->get(route('field'))
            ->assertSee('Treat the machine the way you treat a paper ward file');
    }

    // ── Forms read the form ──────────────────────────────────────────────

    /**
     * Every capture form takes its values from the form, not from a model.
     *
     * The bug: a registration form showing "Muhindo" and "Mubaraka" in the
     * name boxes reported that a first name and a last name were needed. The
     * fields had been filled by the browser's autofill, which can put a value
     * in an input without Alpine ever seeing it, so `x-model` held nothing
     * while the screen plainly showed something.
     *
     * `FormData` cannot disagree with what somebody is looking at, so every
     * control carries a `name` and every handler takes the submitted values
     * as its first argument.
     */
    public function test_every_capture_form_reads_the_form_rather_than_a_model(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views/field')) as $file) {
            $html = File::get($file->getPathname());
            $name = str_replace(resource_path('views/'), '', $file->getPathname());

            // A form whose handler takes no argument is still reading a model.
            if (str_contains($html, 'fieldForm(async function ()')) {
                $offenders[] = "{$name}: a fieldForm handler still takes no submitted values";
            }

            // Inside a <form>, a bound model is a value Alpine may not have.
            if (! str_contains($html, '<form')) {
                continue;
            }

            foreach (explode('<form', $html) as $index => $chunk) {
                if ($index === 0) {
                    continue;
                }

                $form = explode('</form>', $chunk)[0];

                if (str_contains($form, 'x-model')) {
                    $offenders[] = "{$name}: a control inside a form still binds with x-model";
                }
            }
        }

        $this->assertSame([], $offenders, "Forms that can disagree with what is on screen:\n".implode("\n", $offenders));
    }

    public function test_the_offline_rules_are_transcribed_from_the_server_ones(): void
    {
        // Not a diff — the two are different languages. What it asserts is
        // that the transcription names the same fields, so a rule added to
        // `PatientRequest` for a field Field Mode offers cannot silently go
        // unmirrored and be discovered on sync hours later.
        $js = File::get(resource_path('js/offline/validation.js'));

        $this->assertStringContainsString('PatientRequest.php', $js, 'validation.js no longer says what it mirrors.');

        foreach ((new \App\Http\Requests\PatientRequest)->rules() as $field => $rules) {
            // Only the fields a device actually offers.
            if (! preg_match('/name="'.preg_quote($field, '/').'"/', File::get(resource_path('views/field/partials/register.blade.php')))) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^\s*'.preg_quote($field, '/').':/m',
                $js,
                "The offline rules do not mention {$field}, which the register form offers.",
            );
        }
    }

    // ── The worker must never fail to answer ─────────────────────────────

    /**
     * Every `respondWith` path ends in a Response.
     *
     * The bug: `cacheFirst` ended in a bare `await fetch(request)`. Offline,
     * with the asset not cached, that promise REJECTED — and a rejected
     * `respondWith` is exactly what the browser reports as `ERR_FAILED`. The
     * user clicked "Continue in Field Mode" on the worker's own offline door
     * and got the browser's error page.
     *
     * Asserted against the source because there is no service-worker runtime
     * in PHPUnit. Crude, and far better than nothing: the shape of the bug is
     * an unguarded `fetch`, and that is a thing a regex can see.
     */
    public function test_no_worker_handler_can_fail_to_produce_a_response(): void
    {
        $source = $this->worker();

        foreach (['cacheFirst', 'networkFirst', 'adminOrDoor'] as $handler) {
            $body = $this->functionBody($source, $handler);

            $this->assertStringContainsString(
                'catch',
                $body,
                "{$handler}() has no catch: offline it rejects, and a rejected respondWith is ERR_FAILED.",
            );
        }

        // Nothing may call respondWith with a handler that is not one of the
        // three above, since those are the ones proven to catch.
        preg_match_all('/event\.respondWith\(\s*(\w+)\(/', $source, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $called) {
            $this->assertContains($called, ['cacheFirst', 'networkFirst', 'adminOrDoor'], "respondWith calls {$called}(), which is not known to always answer.");
        }
    }

    public function test_the_worker_caches_the_shell_and_its_assets_together(): void
    {
        // The other half of the same failure. Caching the HTML alone leaves a
        // document naming a bundle nobody has — which is the state after every
        // `npm run build`, because the hash in the filename changes.
        $source = $this->worker();

        $this->assertStringContainsString('primeAssets', $source);
        $this->assertStringContainsString('assetUrlsIn', $source);
        $this->assertStringContainsString('shellIsUsable', $source);

        // And the shell is only OFFERED when its assets are really there. A
        // blank Field Mode is worse than a sentence saying what to do.
        $this->assertStringContainsString('shellIsUsable', $this->functionBody($source, 'networkFirst'));
        $this->assertStringContainsString('shellIsUsable', $this->functionBody($source, 'haveWorkingFieldMode'));
    }

    public function test_the_workers_asset_regex_actually_matches_what_vite_emits(): void
    {
        // The dangerous half of the fix. If this pattern does not match the
        // real markup, `primeAssets` caches nothing, `shellIsUsable` is false
        // for ever, and offline mode is permanently off — failing SAFE, but
        // failing silently, which is how the last two of these went unnoticed.
        //
        // So the pattern is read out of the worker and run against the shell
        // this app actually renders. One source of truth, checked both ends.
        preg_match('#const pattern = /(.+)/g;#', $this->worker(), $found);

        $this->assertNotEmpty($found, 'assetUrlsIn() no longer has the pattern this test checks.');

        // `Tests\TestCase` calls `withoutVite()` for the whole suite, so the
        // shell normally renders with no asset tags at all. This one test
        // needs the real ones, because the real ones are the point.
        $this->app->instance(\Illuminate\Foundation\Vite::class, new \Illuminate\Foundation\Vite);

        $html = $this->actingAs($this->nurse)->get(route('field'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#/build/assets/#', $html, 'The shell references no build output at all.');

        // The JS regex translates directly: no flags or classes that differ.
        $matched = preg_match_all('#'.str_replace('#', '\#', $found[1]).'#', $html, $assets);

        $this->assertGreaterThan(0, $matched, 'The worker would cache none of the shell\'s assets.');

        $bundles = array_filter($assets[1], fn ($u) => str_ends_with($u, '.js'));

        $this->assertNotEmpty($bundles, 'The worker would not cache the Field Mode bundle itself.');
    }

    public function test_the_door_never_offers_a_field_mode_that_will_not_open(): void
    {
        // The exact user-visible bug: the door said "Field Mode works without
        // one" and the link it offered led to a browser error page. The door
        // must ask the same question the destination will ask.
        $this->assertStringContainsString(
            'haveWorkingFieldMode',
            $this->functionBody($this->worker(), 'adminOrDoor'),
        );
    }

    public function test_the_retired_kill_switch_cannot_delete_field_modes_caches(): void
    {
        // `public/sw.js` deleted EVERY cache on its way out, including the
        // offline shell. A browser that still has it registered would lose
        // Field Mode with no explanation.
        $this->assertStringContainsString("startsWith('td-field-')", File::get(public_path('sw.js')));
    }

    /** The body of a named function, for asserting about one handler at a time. */
    private function functionBody(string $source, string $name): string
    {
        $start = strpos($source, "function {$name}(");

        $this->assertNotFalse($start, "The worker has no {$name}().");

        $open = strpos($source, '{', $start);
        $depth = 0;

        for ($i = $open; $i < strlen($source); $i++) {
            $depth += $source[$i] === '{' ? 1 : ($source[$i] === '}' ? -1 : 0);

            if ($depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }

        $this->fail("Could not read the body of {$name}().");
    }

    // ── The service worker's rules ───────────────────────────────────────

    private function worker(): string
    {
        $path = public_path('field-sw.js');
        $this->assertFileExists($path);

        return File::get($path);
    }

    /**
     * The worker with its prose removed.
     *
     * The comments in that file talk ABOUT `skipWaiting` and IndexedDB at
     * length, deliberately, because the reasons matter. A test counting
     * occurrences has to count code, or it is measuring how much was explained.
     */
    private function workerCode(): string
    {
        $source = $this->worker();
        $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? '';

        return preg_replace('#^\s*//.*$#m', '', $source) ?? '';
    }

    /**
     * Invariant I-6, made true by construction.
     *
     * A worker with no database code cannot destroy pending work, however
     * badly an update goes. This is the cheapest possible way to hold that
     * guarantee, and the most reliable.
     */
    public function test_the_worker_never_touches_the_local_database(): void
    {
        $source = $this->worker();

        foreach (['indexedDB', 'IDBDatabase', 'Dexie', 'td-offline-'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "The service worker references `{$forbidden}`. It must never touch the local database — "
                .'that is what makes a bad update incapable of destroying unsent work (invariant I-6).',
            );
        }
    }

    /** The 2026 incident: a cache-first worker serving stale assets. */
    public function test_the_worker_never_takes_over_without_being_asked(): void
    {
        $source = $this->workerCode();

        // `skipWaiting` may appear ONLY inside the message handler.
        $occurrences = preg_match_all('/skipWaiting\(\)/', $source);
        $this->assertSame(1, $occurrences, 'skipWaiting() should appear exactly once, in the message handler.');

        $this->assertMatchesRegularExpression(
            "/addEventListener\('message'.*?skipWaiting\(\)/s",
            $source,
            'skipWaiting() must be reachable only from the message handler, so the user has agreed first.',
        );
    }

    public function test_the_worker_never_caches_api_responses(): void
    {
        $this->assertMatchesRegularExpression(
            "#startsWith\('/api/'\)#",
            $this->worker(),
            'API responses carry patient data and must never enter an HTTP cache (plan §14).',
        );
    }

    public function test_the_worker_never_touches_the_livewire_panel(): void
    {
        $source = $this->worker();

        $this->assertStringContainsString("startsWith('/admin')", $source);
        $this->assertStringContainsString("startsWith('/livewire')", $source);
    }

    public function test_the_worker_only_caches_content_hashed_assets(): void
    {
        $source = $this->worker();

        // A URL whose bytes can change must never be cache-first. The hash in
        // the filename is what makes the guarantee, so the pattern must
        // require one.
        $this->assertStringContainsString('isImmutableAsset', $source);
        $this->assertMatchesRegularExpression('/\[A-Za-z0-9_-\]\{8,\}/', $source);
    }

    public function test_the_worker_can_be_switched_off_in_one_deploy(): void
    {
        // Offline mode has to be withdrawable without shipping a client fix
        // and without touching anybody's unsent work (plan §21).
        $this->assertStringContainsString('const UNREGISTER = false;', $this->worker());
        $this->assertStringContainsString('registration.unregister()', $this->worker());
    }

    public function test_the_old_kill_switch_is_still_in_place_for_the_worker_it_retired(): void
    {
        // `public/sw.js` retired a cache-first worker that shipped stale
        // assets. Removing it would let that worker come back to life in any
        // browser that still has it registered.
        $this->assertFileExists(public_path('sw.js'));
        $this->assertStringContainsString('unregister', File::get(public_path('sw.js')));
    }

    // ── Where the app actually lives ─────────────────────────────────────

    /**
     * This installation is served from a subdirectory, not the origin root.
     *
     * The first version of all this hardcoded `/field`, `/api/v1` and
     * `/field-sw.js`. Every one resolved to a path that does not exist here,
     * so the worker never registered and the API was never reachable — and
     * nothing threw, nothing failed, and the feature looked finished.
     */
    public function test_both_layouts_tell_the_client_where_the_app_is(): void
    {
        $base = rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?? '', '/');

        $this->actingAs($this->nurse)
            ->get(route('field'))
            ->assertSee('<meta name="td-base" content="'.$base.'">', false);

        // The admin panel too: a user who only ever opens the panel still gets
        // a worker, and therefore a door when the connection goes.
        $this->actingAs($this->nurse)
            ->get(route('admin.dashboard'))
            ->assertSee('<meta name="td-base" content="'.$base.'">', false);
    }

    public function test_the_build_output_does_not_resolve_chunks_from_the_origin_root(): void
    {
        // The third time this class of bug appeared, and the first one no
        // source grep could have caught: Vite's default `base` is an absolute
        // `/build/`, baked into the module-preload helper. This installation
        // is served from `/true-doctor/`, so every lazily-imported chunk was
        // preloaded from the origin root and 404'd — while the entry files
        // loaded perfectly, because Laravel's `@vite` builds THOSE urls from
        // `APP_URL` and never consults Vite's base at all.
        //
        // `base: './'` makes a chunk resolve against the chunk that imports
        // it, which is right wherever the app is mounted. This asserts the
        // built artefact rather than the config, because the artefact is what
        // ships and a stale build is its own failure mode.
        $this->assertFileExists(public_path('build/manifest.json'), 'Run `npm run build` first.');

        $offenders = [];

        foreach (File::glob(public_path('build/assets/*.js')) as $file) {
            if (str_contains(File::get($file), '"/build/"')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders,
            "Built chunks resolve from the origin root and will 404 under a subdirectory:\n"
            .implode("\n", $offenders));
    }

    public function test_no_offline_source_hardcodes_the_origin_root(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('js/offline')) as $file) {
            $source = File::get($file->getPathname());

            // Comments explain the trap at length; the check is about code.
            $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? '';
            $source = preg_replace('#^\s*//.*$#m', '', $source) ?? '';

            // A literal INSIDE appPath() is the correct usage — that is the
            // whole point of the helper. Only a bare one is the bug.
            $source = preg_replace('#appPath\([^)]*\)#', 'appPath()', $source) ?? '';

            foreach (["'/api/", "'/field'", "'/admin"] as $literal) {
                if (str_contains($source, $literal)) {
                    $offenders[] = $file->getFilename().' → '.$literal;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These assume the app is at the origin root. It is not — use appPath():\n"
            .implode("\n", $offenders),
        );
    }

    public function test_the_worker_offers_a_door_when_the_panel_cannot_be_reached(): void
    {
        $worker = $this->worker();

        // The panel is Livewire and cannot work offline. Somebody who types
        // its URL with no connection should be told that, and offered the
        // thing that does work — not left with the browser's error page.
        $this->assertStringContainsString('adminOrDoor', $worker);
        $this->assertStringContainsString('Continue in Field Mode', $worker);

        // Still never CACHED: the door is generated, and rule 6 stands.
        $this->assertDoesNotMatchRegularExpression('/cache\.put\([^)]*admin/i', $worker);
    }

    // ── The client bundle ────────────────────────────────────────────────

    public function test_field_mode_bundles_its_own_alpine_and_admin_does_not(): void
    {
        $field = File::get(resource_path('js/field.js'));
        $admin = File::get(resource_path('js/admin.js'));

        // Field Mode has no Livewire on the page, so nothing else supplies
        // Alpine. The admin layout gets it from Livewire, and a second copy
        // would double-boot every x-data there.
        $this->assertStringContainsString("from 'alpinejs'", $field);
        $this->assertStringNotContainsString("from 'alpinejs'", $admin);
    }

    public function test_the_offline_modules_are_reachable_from_a_build_entry(): void
    {
        $vite = File::get(base_path('vite.config.js'));

        // Unbuilt front-end code is code nobody runs, and the stale-build
        // guard in DesignSystemCssTest fires on it.
        $this->assertStringContainsString('resources/js/field.js', $vite);
        $this->assertStringContainsString('resources/css/field.css', $vite);
    }
}
