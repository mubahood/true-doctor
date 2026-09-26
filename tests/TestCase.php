<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Layouts load assets via @vite; the suite must not depend on a build
        // (CI builds separately to prove the pipeline compiles).
        $this->withoutVite();

        // `Livewire::withoutLazyLoading()` sets a STATIC that outlives the test
        // that set it, so a class which arms it silently changes how every
        // later class in the same process renders. DashboardSectionTest asserts
        // that a lazy section serves a skeleton first, and it passed or failed
        // purely on whether DashboardHttpTest — which arms the flag in its own
        // setUp — happened to run before it.
        //
        // Every test therefore starts from lazy loading ON, which is how the
        // browser behaves, and a test that wants it off says so itself.
        SupportLazyLoading::$disableWhileTesting = false;
    }

    /**
     * The picture check, answered as a person would: the code from the
     * picture, on a form drawn ten seconds ago, with the hidden field empty.
     *
     * @return array<string,string>
     */
    protected function humanCheck(string $form): array
    {
        $id = \App\Support\HumanCheck::issue($form);

        return [
            'human_id' => $id,
            'human_answer' => strtolower((string) \App\Support\HumanCheck::code($id)),
            'human_started' => \Illuminate\Support\Facades\Crypt::encryptString($form.'|'.(time() - 10)),
        ];
    }
}
