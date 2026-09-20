<?php

// Bootstrap file for subdirectory hosting on MAMP.
// Routes all requests through public/index.php while keeping
// SCRIPT_NAME as /devrootsacademy/index.php so Laravel
// correctly strips the subdirectory prefix from REQUEST_URI.
require __DIR__.'/public/index.php';
