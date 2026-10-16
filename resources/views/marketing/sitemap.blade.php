{{-- Emitted as a string, not written literally: the production host has
     short_open_tag on, where a literal `<?xml` opens a PHP block. --}}
{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach($pages as $page)
  <url>
    <loc>{{ $page['loc'] }}</loc>
    <lastmod>{{ $page['lastmod'] }}</lastmod>
    <changefreq>{{ $page['changefreq'] }}</changefreq>
    <priority>{{ $page['priority'] }}</priority>
  </url>
@endforeach
</urlset>
