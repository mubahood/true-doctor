{{-- One document of the data protection pack (docs/compliance/*.md), plain: black text, hairlines, no colour. --}}
<x-pdf.document :title="$title" :meta="['Prepared' => now()->format('j M Y')]" accent="black">
  <style>
    .md h2 { color: black; }
    .md h3 { font-size: 10px; margin: 12px 0 4px; color: black; }
    .md p { margin: 0 0 7px; }
    .md ul, .md ol { margin: 0 0 8px; padding-left: 16px; }
    .md li { margin-bottom: 2px; }
    .md table { width: 100%; border-collapse: collapse; margin: 4px 0 10px; }
    .md th, .md td { border: .5px solid gray; padding: 4px 5px; text-align: left; vertical-align: top; font-size: 9px; }
    .md th { font-weight: bold; }
    .md hr { border: none; border-top: .5px solid gray; margin: 12px 0; }
  </style>
  <div class="md">{!! $html !!}</div>
</x-pdf.document>
