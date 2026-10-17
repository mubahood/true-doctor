<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The user manual (resources/manual/*.md): one Markdown file per chapter, in
 * file-name order, the first `# ` line its title. The same files are the web
 * pages, the PDF, the phone's reader and the copy inside a one-off package,
 * so there is one manual to keep right.
 */
final class Manual
{
    public static function path(): string
    {
        return resource_path('manual');
    }

    /** @return list<array{slug:string,title:string,order:int}> */
    public static function chapters(): array
    {
        $out = [];
        foreach (glob(self::path().'/[0-9][0-9]-*.md') ?: [] as $file) {
            $slug = basename($file, '.md');
            $out[] = ['slug' => $slug, 'title' => self::titleOf((string) file_get_contents($file), $slug), 'order' => (int) substr($slug, 0, 2)];
        }

        return $out;
    }

    /** @return array{slug:string,title:string,markdown:string}|null */
    public static function chapter(string $slug): ?array
    {
        if (preg_match('/^\d{2}-[a-z0-9-]+$/', $slug) !== 1 || ! is_file($file = self::path().'/'.$slug.'.md')) {
            return null;
        }
        $markdown = (string) file_get_contents($file);

        return ['slug' => $slug, 'title' => self::titleOf($markdown, $slug), 'markdown' => $markdown];
    }

    /**
     * A chapter as HTML, its links to other chapters pointed at $route (the
     * public pages or the back office), its own `# ` title left to the page.
     */
    public static function html(string $markdown, string $route): string
    {
        $body = (string) preg_replace('/^# .*\R/', '', $markdown, 1);
        $html = Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        // Headings get the usual anchors ("## Roles: who does what" -> #roles-who-does-what).
        $html = (string) preg_replace_callback('/<h([23])>(.*?)<\/h\1>/s', fn ($m) => '<h'.$m[1].' id="'.Str::slug(html_entity_decode(strip_tags($m[2]))).'">'.$m[2].'</h'.$m[1].'>', $html);

        return (string) preg_replace_callback('/href="(\d{2}-[a-z0-9-]+)\.md(#[^"]*)?"/', fn ($m) => 'href="'.e(route($route, $m[1])).($m[2] ?? '').'"', $html);
    }

    /** Changes whenever any chapter changes: the phone's cache key. */
    public static function version(): string
    {
        $stamp = '';
        foreach (glob(self::path().'/*.md') ?: [] as $file) {
            $stamp .= basename($file).':'.sha1_file($file).';';
        }

        return substr(sha1($stamp), 0, 12);
    }

    private static function titleOf(string $markdown, string $slug): string
    {
        return preg_match('/^# (.+)$/m', $markdown, $m) === 1 ? trim($m[1]) : Str::headline(substr($slug, 3));
    }
}
