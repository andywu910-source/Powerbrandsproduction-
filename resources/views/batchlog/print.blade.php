<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Batch {{ $s['batch_number'] }} – batch record summary</title>
<style>
    @page { margin: 14mm 12mm 16mm; }
    body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 10px; color: #0f1b33; margin: 0; }
    h1 { font-size: 17px; margin: 0 0 2px; color: #14378f; }
    h4 { font-size: 12px; margin: 14px 0 5px; color: #14378f; border-bottom: 1.5px solid #1e4fd8; padding-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
    tr { page-break-inside: avoid; }
    th, td { text-align: left; padding: 3px 5px; border: 1px solid #d9e0ea; vertical-align: top; }
    th { background: #eaf0fd; font-size: 9px; }
    .bs-kv td.k { width: 22%; color: #4a5873; background: #f6f8fc; }
    .bs-bad { color: #b42318; font-weight: bold; }
    .bs-ok { color: #067647; font-weight: bold; }
    .bs-sig { color: #4a5873; font-size: 9px; }
    .bs-sig s { color: #98a2b3; }
    .head td { border: none; padding: 0; }
    .muted { color: #4a5873; }
    .foot { margin-top: 14px; font-size: 8.5px; color: #4a5873; border-top: 1px solid #d9e0ea; padding-top: 5px; }
    .noprint { margin: 10px 0; }
    @media print { .noprint { display: none; } }
    .pill { display: inline-block; padding: 1px 6px; border-radius: 8px; border: 1px solid #d9e0ea; font-size: 9px; }
    .pill.ok { color: #067647; border-color: #abefc6; } .pill.bad { color: #b42318; border-color: #fecdca; } .pill.warn { color: #b54708; border-color: #fedf89; }
</style>
</head>
<body>
@unless ($pdf)<div class="noprint"><button onclick="window.print()">Print</button></div>@endunless
<table class="head"><tr>
    <td><h1>PowerBrands Productions – Batch record summary</h1>
        <div class="muted">Batch <b>{{ $s['batch_number'] }}</b> · {{ $s['bpr_id'] }} · {{ $s['product'] }} ({{ $s['brand'] }}) · Status: <b>{{ $s['status'] }}</b></div></td>
</tr></table>
@include('batchlog.partials.summary', ['s' => $s, 'pdf' => true])
<div class="foot">Generated {{ $generatedAt }} by {{ $generatedBy }} from the signed electronic records. Record fingerprint (SHA-256): {{ $s['hash'] }}.
    Internal use only – PowerBrands NY production &amp; compliance records.</div>
</body>
</html>
