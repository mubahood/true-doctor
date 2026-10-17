{{-- The user manual as one plain PDF (App\Http\Controllers\ManualController::pdfPath). Black on white, hairlines, no colour. --}}
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>True-Doctor user manual</title>
<style>
  @page { margin: 22mm 18mm 20mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; line-height: 1.5; color: black; }
  h1 { font-size: 18px; margin: 0 0 10px; }
  h2 { font-size: 13px; margin: 16px 0 6px; }
  h3 { font-size: 11px; margin: 12px 0 4px; }
  p { margin: 0 0 7px; }
  ul, ol { margin: 0 0 8px; padding-left: 18px; }
  li { margin-bottom: 2px; }
  table { width: 100%; border-collapse: collapse; margin: 4px 0 10px; }
  th, td { border: .5px solid gray; padding: 4px 5px; text-align: left; vertical-align: top; }
  th { font-weight: bold; }
  a { color: black; }
  code { font-family: DejaVu Sans Mono, monospace; font-size: 9px; }
  .cover { text-align: center; padding-top: 70mm; }
  .cover h1 { font-size: 28px; }
  .cover p { font-size: 12px; }
  .toc li { margin-bottom: 4px; list-style: none; }
  .chapter { page-break-before: always; }
  .footer { position: fixed; bottom: -12mm; left: 0; right: 0; text-align: center; font-size: 8px; color: dimgray; }
</style></head>
<body>
  <div class="footer">True-Doctor user manual</div>
  <div class="cover">
    <h1>True-Doctor</h1>
    <p>User manual</p>
    <p>{{ now()->format('F Y') }}</p>
  </div>
  <div class="chapter">
    <h1>Contents</h1>
    <ol class="toc">
      @foreach($chapters as $c)<li><a href="#{{ $c['slug'] }}">{{ $c['order'] }}. {{ $c['title'] }}</a></li>@endforeach
    </ol>
  </div>
  @foreach($chapters as $c)
    <div class="chapter" id="{{ $c['slug'] }}">
      <h1>{{ $c['order'] }}. {{ $c['title'] }}</h1>
      {!! $c['html'] !!}
    </div>
  @endforeach
</body></html>
