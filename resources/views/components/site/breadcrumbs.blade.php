@props(['trail' => []])
{{-- BreadcrumbList for search engines; the last entry is the page itself (no link). --}}
<script type="application/ld+json">
  {!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => array_values(array_map(
      fn ($name, $url, $i) => array_filter(['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $url ?? url()->current()]),
      array_keys($trail), array_values($trail), array_keys(array_values($trail)),
    )),
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
