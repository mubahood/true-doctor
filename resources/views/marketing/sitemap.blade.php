{{-- Emitted as a string, not written literally: the production host has
     short_open_tag on, where a literal `<?xml` opens a PHP block and the
     view dies with `syntax error, unexpected identifier "version"`. Local
     PHP has it off, so this only ever breaks in production. --}}
{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach($pages as $name => [$priority, $frequency])
  <url>
    <loc>{{ route($name) }}</loc>
    <changefreq>{{ $frequency }}</changefreq>
    <priority>{{ $priority }}</priority>
  </url>
@endforeach
</urlset>
