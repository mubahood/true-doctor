import { defineConfig } from 'vitest/config';

// The offline client is the only JavaScript in this project with logic worth
// testing, and none of it can be reached from PHPUnit. `fake-indexeddb` gives
// a real IndexedDB implementation in Node, so the outbox, the repositories and
// the schema migrations are tested against the same storage semantics the
// browser has — transactions, versioned upgrades and all.
export default defineConfig({
    test: {
        environment: 'node',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
        restoreMocks: true,
    },
});
