@props(['width' => 80, 'title' => ''])
@php($brand = app(\App\Support\DocumentBrand::class)->resolve())
{{-- A roll-paper document (58 or 80 mm) for a receipt printer: one column,
     the clinic at the top, nothing wider than the paper. DomPDF needs its
     styles in the document; sizes follow the paper. --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{{ $title }}</title>
  <style>
    @page { margin: 8px {{ $width === 58 ? 6 : 10 }}px 10px {{ $width === 58 ? 6 : 10 }}px; }
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: {{ $width === 58 ? '7.6' : '8.6' }}px; color: #000; margin: 0; }
    .c { text-align: center; }
    .r { text-align: right; }
    .clinic { font-size: {{ $width === 58 ? '10' : '11.5' }}px; font-weight: bold; }
    .small { font-size: {{ $width === 58 ? '6.6' : '7.4' }}px; }
    .big { font-size: {{ $width === 58 ? '20' : '26' }}px; font-weight: bold; }
    .title { font-weight: bold; letter-spacing: 1px; margin-top: 4px; }
    .rule { border-top: 1px dashed #000; margin: 5px 0; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 1px 0; vertical-align: top; }
    .num { font-family: "DejaVu Sans Mono", monospace; white-space: nowrap; }
    .total td { font-weight: bold; padding-top: 3px; }
  </style>
</head>
<body>
  <div class="c">
    <div class="clinic">{{ $brand['name'] ?? 'True-Doctor' }}</div>
    @if(! empty($brand['address']))<div class="small">{{ $brand['address'] }}</div>@endif
    @if(! empty($brand['phone']))<div class="small">Tel {{ $brand['phone'] }}</div>@endif
  </div>
  <div class="rule"></div>
  {{ $slot }}
</body>
</html>
