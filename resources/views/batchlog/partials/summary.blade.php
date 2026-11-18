@php
    $sig = function (array $sigs) {
        if (! $sigs) return '<span class="bs-sig">no signatures</span>';
        return collect($sigs)->map(fn ($g) => '<span class="bs-sig">'.($g['invalid'] ? '<s>' : '').e($g['meaning']).': <b>'.e($g['name']).'</b> '.e($g['at']).($g['invalid'] ? '</s> (re-sign needed after a correction)' : '').'</span>')->implode('<br>');
    };
    $yn = fn ($v) => $v === null ? '—' : ($v ? '<span class="bs-ok">OK</span>' : '<span class="bs-bad">OUT</span>');
    $tbl = $pdf ? '' : 'pb-table';
@endphp

<div class="bs-section"><h4>Batch</h4>
<table class="bs-kv">
    <tr><td class="k">Batch number</td><td><b>{{ $s['batch_number'] }}</b> ({{ $s['bpr_id'] }})</td><td class="k">Status</td><td><span style="color:{{ \App\Models\Batch::LOG_STATUS_COLORS[$s['status_key']] ?? '#111827' }};font-weight:700">{{ $s['status'] }}</span></td></tr>
    <tr><td class="k">Product</td><td>{{ $s['product'] }} · {{ $s['brand'] }} · {{ $s['family'] }}</td><td class="k">Hardware SKU</td><td>{{ $s['hardware_sku'] ?: '—' }}</td></tr>
    <tr><td class="k">Recipe (MMP)</td><td>{{ $s['mmp'] }}</td><td class="k">Quality plan (PQP)</td><td>{{ $s['pqp'] ?: '—' }}</td></tr>
    <tr><td class="k">Units planned</td><td>{{ $s['target_units'] === null ? '—' : number_format($s['target_units']) }}</td><td class="k">Batch size</td><td>{{ $s['batch_size'] ?: '—' }}</td></tr>
    <tr><td class="k">Target potency</td><td>{{ $s['target_potency'] !== null ? $s['target_potency'].($s['potency_basis'] === 'mg' ? ' mg/unit' : ' %') : '—' }}</td><td class="k">Room / line</td><td>{{ $s['room'] ?: '—' }}{{ $s['line'] ? ' · '.$s['line'] : '' }}</td></tr>
    <tr><td class="k">Started</td><td>{{ $s['started_at'] }}</td><td class="k">Finished</td><td>{{ $s['completed_at'] ?: '—' }}</td></tr>
    @foreach ($s['formulation'] as $k => $v)<tr><td class="k">{{ $k }}</td><td colspan="3">{{ $v }}</td></tr>@endforeach
    @if ($s['void_reason'])<tr><td class="k">Voided</td><td colspan="3" class="bs-bad">{{ $s['void_reason'] }}</td></tr>@endif
</table></div>

<div class="bs-section"><h4>Results</h4>
<table class="bs-kv">
    <tr><td class="k">Units packed</td><td>{{ $s['units_packed'] !== null ? number_format($s['units_packed']) : '—' }}</td><td class="k">Yield</td><td>{{ $s['yield_pct'] !== null ? (float) $s['yield_pct'].'%' : '—' }} (allowed {{ $s['yield_range'] }})</td></tr>
    <tr><td class="k">Short of plan</td><td>{{ $s['losses'] !== null ? number_format($s['losses']).' units' : '—' }}</td><td class="k">Output Metrc UID</td><td>{{ $s['output_metrc_uid'] ?: '—' }}</td></tr>
    <tr><td class="k">Input Metrc UIDs</td><td colspan="3">{{ implode(', ', $s['input_metrc_uids']) ?: '—' }}</td></tr>
    <tr><td class="k">COA</td><td>{{ $s['coa']['lab'] ?: '—' }} · sample {{ $s['coa']['sample_id'] ?: '—' }}</td><td class="k">COA result</td><td>{!! $s['coa']['result'] === 'pass' ? '<span class="bs-ok">PASS</span>' : ($s['coa']['result'] === 'fail' ? '<span class="bs-bad">FAIL</span>' : '—') !!}{{ $s['coa']['potency'] !== null ? ' · potency '.$s['coa']['potency'] : '' }}</td></tr>
    @if (config('pbp.qc_release_step') || $s['qc']['decision'])<tr><td class="k">QC decision</td><td>{{ $s['qc']['decision'] ? ucfirst($s['qc']['decision']) : 'Not decided yet' }}{{ $s['qc']['reviewer'] ? ' by '.$s['qc']['reviewer'] : '' }}{{ $s['qc']['note'] ? ' – '.$s['qc']['note'] : '' }}</td><td class="k">QC signature</td><td>{!! $sig($s['qc']['signatures']) !!}</td></tr>@endif
</table></div>

<div class="bs-section"><h4>Steps and sign-offs</h4>
<table class="{{ $tbl }}"><thead><tr><th>#</th><th>Step</th><th>Status</th><th>Completed</th><th>People</th><th>Signatures</th></tr></thead><tbody>
@foreach ($s['steps'] as $st)
    <tr><td>{{ $st['no'] }}</td><td>{{ $st['title'] }}</td><td>{{ ucfirst($st['status']) }}</td><td>{{ $st['completed_at'] }}</td><td>{{ implode(', ', $st['staff']) }}</td><td>{!! $sig($st['signatures']) !!}</td></tr>
@endforeach
</tbody></table></div>

<div class="bs-section"><h4>Crew, PPE and equipment</h4>
<table class="{{ $tbl }}"><thead><tr><th>Person</th><th>Task</th><th>PPE</th></tr></thead><tbody>
@forelse ($s['crew'] as $c)<tr><td>{{ $c['name'] }}</td><td>{{ $c['task'] }}</td><td>{!! $yn($c['ppe']) !!}</td></tr>@empty<tr><td colspan="3">—</td></tr>@endforelse
</tbody></table>
<table class="{{ $tbl }}" style="margin-top:6px"><thead><tr><th>Equipment</th><th>Name</th><th>Calibration due</th></tr></thead><tbody>
@forelse ($s['equipment'] as $e)<tr><td>{{ $e['id'] }}</td><td>{{ $e['name'] }}</td><td>{{ $e['cal_due'] ?: '—' }}</td></tr>@empty<tr><td colspan="3">—</td></tr>@endforelse
</tbody></table>
@if ($s['cleaning'])
<table class="{{ $tbl }}" style="margin-top:6px"><thead><tr><th>Changeover cleaning</th><th>Status</th><th>Signatures</th></tr></thead><tbody>
@foreach ($s['cleaning'] as $c)<tr><td>{{ $c['id'] }}</td><td>{{ ucfirst(str_replace('_', ' ', $c['status'])) }}</td><td>{!! $sig($c['signatures']) !!}</td></tr>@endforeach
</tbody></table>
@endif
</div>

<div class="bs-section"><h4>Materials (weighed and verified)</h4>
<table class="{{ $tbl }}"><thead><tr><th>Ingredient</th><th>Lot</th><th>Supplier / lot</th><th>Metrc UID</th><th>Planned</th><th>Actual</th><th>Variance</th><th>Within</th><th>Signatures</th></tr></thead><tbody>
@forelse ($s['materials'] as $m)
    <tr @if ($m['status'] === 'void') style="text-decoration:line-through;color:#98a2b3" @endif>
        <td>{{ $m['ingredient'] }}{{ $m['void_reason'] ? ' (void: '.$m['void_reason'].')' : '' }}</td><td>{{ $m['lot'] }}</td><td>{{ $m['supplier'] }} {{ $m['supplier_lot'] }}</td><td>{{ $m['metrc'] }}</td>
        <td>{{ $m['planned'] }}</td><td>{{ $m['actual'] }}</td><td>{{ $m['variance'] !== null ? $m['variance'].'%' : '' }}{{ $m['tolerance'] !== null ? ' (±'.$m['tolerance'].'%)' : '' }}</td>
        <td>{!! $yn($m['within']) !!}{{ $m['deviation'] ? ' · '.$m['deviation'] : '' }}</td><td>{!! $sig($m['signatures']) !!}</td></tr>
@empty<tr><td colspan="9">—</td></tr>@endforelse
</tbody></table>
@if ($s['ledger'])
<table class="{{ $tbl }}" style="margin-top:6px"><thead><tr><th>Stock used from lot</th><th>Amount</th><th>Left in lot after</th><th>When</th></tr></thead><tbody>
@foreach ($s['ledger'] as $l)<tr><td>{{ $l['lot'] }}</td><td>{{ $l['qty'] }}</td><td>{{ $l['balance_after'] }}</td><td>{{ $l['at'] }}</td></tr>@endforeach
</tbody></table>
@endif
</div>

<div class="bs-section"><h4>Manufacturing steps</h4>
<table class="{{ $tbl }}"><thead><tr><th>#</th><th>Stage / instruction</th><th>Check</th><th>Limits</th><th>Actual</th><th>Within</th><th>Done</th><th>Signatures</th></tr></thead><tbody>
@forelse ($s['mmp_steps'] as $r)
    <tr><td>{{ $r['no'] }}</td><td>{{ ucfirst($r['stage']) }} – {{ $r['instruction'] }}{{ $r['observation'] ? ' · '.$r['observation'] : '' }}</td><td>{{ $r['parameter'] }}</td><td>{{ $r['limits'] }}</td>
        <td>{{ $r['actual'] }}@foreach ($r['revisions'] as $v)<br><span class="bs-sig">was <s>{{ $v['old'] }}</s> → {{ $v['new'] }} ({{ $v['reason'] }}, {{ $v['initials'] }} {{ $v['at'] }})</span>@endforeach</td>
        <td>{!! $yn($r['within']) !!}{{ $r['deviation'] ? ' · '.$r['deviation'] : '' }}</td>
        <td>{{ $r['performed_at'] }}<br>{{ implode(', ', $r['staff']) }}{{ $r['late'] ? ' (late: '.$r['late'].')' : '' }}</td><td>{!! $sig($r['signatures']) !!}</td></tr>
@empty<tr><td colspan="8">—</td></tr>@endforelse
</tbody></table></div>

<div class="bs-section"><h4>Packaging, labels and lab sampling</h4>
<table class="bs-kv">
    <tr><td class="k">Label version</td><td>{{ $s['packaging']['label_version'] ?? '—' }}</td><td class="k">Labels issued / used / destroyed / returned</td>
        <td>{{ $s['packaging']['labels_issued'] ?? '—' }} / {{ $s['packaging']['labels_used'] ?? '—' }} / {{ $s['packaging']['labels_destroyed'] ?? '—' }} / {{ $s['packaging']['labels_returned'] ?? '—' }}</td></tr>
    <tr><td class="k">Lab sampling</td><td>{{ $s['lab_sampling']['lab_name'] ?? '—' }} · {{ $s['lab_sampling']['sampler_name'] ?? '' }} · {{ $s['lab_sampling']['sampled_at'] ?? '' }}</td>
        <td class="k">Video / retained samples</td><td>{{ $s['lab_sampling']['video_id'] ?? '—' }} · {{ $s['lab_sampling']['retained_qty'] ?? '' }} {{ isset($s['lab_sampling']['retained_location']) ? '@ '.$s['lab_sampling']['retained_location'] : '' }}</td></tr>
</table></div>

<div class="bs-section"><h4>Deviations</h4>
<table class="{{ $tbl }}"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Root cause / action / disposition</th><th>Signatures</th></tr></thead><tbody>
@forelse ($s['deviations'] as $d)
    <tr><td>{{ $d['id'] }}</td><td>{{ $d['title'] }}</td><td>{{ $d['status'] }}</td><td>{{ $d['root_cause'] }}{{ $d['corrective_action'] ? ' / '.$d['corrective_action'] : '' }}{{ $d['disposition'] ? ' / '.$d['disposition'] : '' }}</td><td>{!! $sig($d['signatures']) !!}</td></tr>
@empty<tr><td colspan="5">None.</td></tr>@endforelse
</tbody></table></div>

<div class="bs-section"><h4>Files and filing</h4>
<table class="{{ $tbl }}"><thead><tr><th>Kind</th><th>File</th><th>SHA-256</th></tr></thead><tbody>
@forelse ($s['files'] as $f)
    <tr><td>{{ strtoupper($f['kind']) }}</td><td>@if ($pdf){{ $f['filename'] }}@else<a href="{{ route('files.download', $f['id']) }}">{{ $f['filename'] }}</a>@endif</td><td style="font-size:8px">{{ $f['sha256'] }}</td></tr>
@empty<tr><td colspan="3">No files.</td></tr>@endforelse
</tbody></table>
<p class="bs-sig">Google Drive: {{ $s['drive'] }} · Keep until: {{ $s['retention_until'] ?: '—' }}</p>
</div>
