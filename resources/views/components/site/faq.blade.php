@props([
    /** @var list<array{0:string,1:string}> question, answer */
    'items' => [],
])
{{--
  Questions and answers, as <details>.

  Native disclosure rather than JavaScript: it opens with the script blocked,
  it is keyboard-operable for free, the browser's own find-in-page can reach
  inside a closed one, and it prints open. Nothing a hand-rolled accordion
  does here would be an improvement.
--}}
<div class="faq">
  @foreach($items as [$question, $answer])
    <details @if($loop->first) open @endif>
      <summary>{!! $question !!}</summary>
      <div class="a"><p>{!! $answer !!}</p></div>
    </details>
  @endforeach
</div>

{{-- The same questions, for a search engine. --}}
@push('structured-data')
<script type="application/ld+json">
  {!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(fn ($item) => [
      '@type' => 'Question',
      'name' => strip_tags(html_entity_decode($item[0])),
      'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags(html_entity_decode($item[1]))],
    ], $items),
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush
