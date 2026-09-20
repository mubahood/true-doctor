<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #14202b; }
  .card { width: 297px; height: 210px; padding: 12px 14px; position: relative; }
  .head { width: 100%; border-collapse: collapse; }
  .head td { padding: 0; vertical-align: middle; border: none; }
  .logo { height: 24px; width: auto; max-width: 68px; }
  .brand { font-size: 11px; font-weight: bold; color: #0e6b63; letter-spacing: .3px; line-height: 1.15; }
  .sub { font-size: 7px; color: #6b7a86; margin-top: 1px; text-transform: uppercase; letter-spacing: .6px; }
  .rule { height: 2px; background: #0e6b63; margin: 7px 0 9px; }
  .name { font-size: 15px; font-weight: bold; margin: 0 0 2px; }
  .no { font-size: 10px; color: #0e6b63; font-weight: bold; letter-spacing: .5px;
        font-family: "DejaVu Sans Mono", monospace; }
  table.meta { width: 100%; font-size: 8px; margin-top: 8px; border-collapse: collapse; }
  table.meta td { padding: 2px 0; vertical-align: top; }
  table.meta td.k { color: #6b7a86; width: 42px; }
  .qr { position: absolute; right: 14px; bottom: 12px; text-align: center; }
  .qr img { width: 76px; height: 76px; }
  .qr .cap { font-size: 6px; color: #6b7a86; margin-top: 1px; }
  .foot { position: absolute; left: 14px; bottom: 12px; font-size: 6.5px; color: #9aa7b1; }
</style>
</head>
<body>
  <div class="card">
    {{-- The card is a physical object, not a sheet of A4, so it keeps its own
         layout — but it carries the same mark as everything else the hospital
         prints (docs/documents.md). --}}
    @php($brand = app(\App\Support\DocumentBrand::class)->resolve())
    <table class="head">
      <tr>
        @if($brand['logo'])
          <td style="width:1%; padding-right:7px;"><img src="{{ $brand['logo'] }}" alt="" class="logo"></td>
        @endif
        <td>
          <div class="brand">{{ $brand['name'] }}</div>
          <div class="sub">Patient Identification Card</div>
        </td>
      </tr>
    </table>
    <div class="rule"></div>

    <div class="name">{{ $patient->full_name }}</div>
    <div class="no">{{ $patient->patient_no }}</div>

    <table class="meta">
      <tr><td class="k">Sex</td><td>{{ $patient->sex?->label() ?? '—' }}</td></tr>
      <tr><td class="k">DOB</td><td>{{ $patient->dob?->format('d M Y') ?? '—' }}</td></tr>
      <tr><td class="k">Blood</td><td>{{ $patient->blood_type ?? '—' }}</td></tr>
      <tr><td class="k">Phone</td><td>{{ $patient->phone_1 ?? '—' }}</td></tr>
    </table>

    <div class="qr">
      <img src="{{ $qr }}" alt="QR">
      <div class="cap">Scan to verify</div>
    </div>

    <div class="foot">
      Issued {{ $patient->created_at->format('d M Y') }} · Property of {{ $brand['name'] }}
      @if($brand['phone'])<br>{{ $brand['phone'] }}@endif
    </div>
  </div>
</body>
</html>
