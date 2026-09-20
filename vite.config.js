import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// One entry per layout.
//
// Alpine is not bundled for the ADMIN entries — Livewire 3 ships its own copy
// via @livewireScripts, and a second instance would double-boot every x-data.
// `field.js` is the exception and bundles it deliberately: Field Mode is
// client-rendered with no Livewire on the page, so nothing else would supply
// it (plan §2.1).
export default defineConfig({
    // Relative, so a code-split chunk is fetched from wherever the chunk that
    // imports it actually lives.
    //
    // The default is an absolute `/build/`, which Vite bakes into the preload
    // helper — and this installation is served from `/true-doctor/`, so every
    // modulepreload for a lazily-imported chunk 404'd against the origin root.
    // That is the same class of bug as the worker hardcoding `/field`: nothing
    // throws, the entry files load correctly because Laravel's `@vite` builds
    // those URLs from `APP_URL`, and only the split chunks quietly fail.
    //
    // Relative rather than derived from `APP_URL` on purpose: a base that has
    // to be configured per environment is a base that will be wrong in one of
    // them, and this one cannot be.
    base: './',
    plugins: [
        laravel({
            input: [
                'resources/css/admin.css',
                'resources/js/admin.js',
                'resources/js/auth.js',
                'resources/css/auth.css',
                'resources/css/marketing.css',
                'resources/js/marketing.js',
                'resources/css/field.css',
                'resources/js/field.js',
            ],
            refresh: ['resources/views/**'],
        }),
    ],
});
