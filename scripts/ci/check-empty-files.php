<?php

/**
 * HMS_PLAN.md constraint A4: fail the build on empty/stub PHP class files.
 * The legacy audits found ~25 same-day stub files and 0-byte Services/
 * Repositories shipped as if they were real features — this check exists
 * so that never happens again here.
 *
 * A file is a "stub" if, once whitespace/comments/the opening tag are
 * stripped, it has no executable content beyond a namespace + class/
 * interface/trait/enum declaration with an empty body.
 */
$roots = ['app', 'database', 'routes', 'tests'];
$excludePrefixes = ['database/migrations/__none__/'];

$failures = [];

$rii = [];
foreach ($roots as $root) {
    if (! is_dir(__DIR__.'/../../'.$root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../'.$root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = $root.'/'.substr($file->getPathname(), strlen(__DIR__.'/../../'.$root) + 1);
        foreach ($excludePrefixes as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                continue 2;
            }
        }
        $rii[] = [$relative, $file->getPathname()];
    }
}

foreach ($rii as [$relative, $path]) {
    $size = filesize($path);
    if ($size === 0) {
        $failures[] = "{$relative}: 0-byte file";

        continue;
    }

    $tokens = token_get_all(file_get_contents($path));
    $meaningful = array_values(array_filter($tokens, function ($t) {
        if (is_string($t)) {
            return true;
        }
        [$id] = $t;

        return ! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true);
    }));

    if (count($meaningful) === 0) {
        $failures[] = "{$relative}: no executable content (comments/whitespace only)";

        continue;
    }

    // Detect `class X { }` / `class X extends Y { }` with a wholly empty
    // body: namespace decl (optional) + declaration keyword + name +
    // optional extends/implements clause + `{` + `}` and nothing else.
    $ids = array_map(fn ($t) => is_array($t) ? $t[0] : $t, $meaningful);
    $isDeclaration = in_array(T_CLASS, $ids, true) || in_array(T_INTERFACE, $ids, true)
        || in_array(T_TRAIT, $ids, true) || (defined('T_ENUM') && in_array(T_ENUM, $ids, true));

    // An empty `abstract class X extends Base {}` is a legitimate
    // customization point (e.g. tests/TestCase.php), not a stub feature —
    // only flag empty *concrete* declarations.
    $isAbstract = in_array(T_ABSTRACT, $ids, true);

    if ($isDeclaration && ! $isAbstract) {
        $braceOpenIdx = array_search('{', $ids, true);
        $braceCloseIdx = null;
        foreach ($ids as $i => $id) {
            if ($id === '}') {
                $braceCloseIdx = $i;
            }
        }
        if ($braceOpenIdx !== false && $braceCloseIdx !== null && $braceCloseIdx === $braceOpenIdx + 1) {
            $failures[] = "{$relative}: empty class/interface/trait body";
        }
    }
}

if ($failures) {
    fwrite(STDERR, "Empty/stub PHP file check failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'check-empty-files: OK ('.count($rii)." files scanned)\n";
exit(0);
