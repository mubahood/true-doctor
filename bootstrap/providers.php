<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    // TelescopeServiceProvider is registered conditionally (local only) in
    // AppServiceProvider::register() so production --no-dev installs (which omit
    // the dev-only telescope package) don't fatal on the missing parent class.
];
