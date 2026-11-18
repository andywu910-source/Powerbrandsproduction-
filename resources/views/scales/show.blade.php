@extends('layouts.app')
@section('title', $scale->equip_id)
@section('page_title', $scale->equip_id.' · '.$scale->name)
@section('parent_title', 'Scale register')
@section('parent_url', route('scales.index'))
@php use App\Models\Equipment; use App\Models\ScaleInspection; use App\Support\Fmt; $st = $scale->inspectionStatus(); @endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>Status</h3><span class="pill {{ Equipment::statusPill($st) }}">{{ Equipment::statusLabel($st) }}</span></div>
    <div class="pb-kv">
        <div><span>Last inspection</span><b>{{ Fmt::date($scale->last_inspected_on) ?: '—' }}</b></div>
        <div><span>Next inspection due</span><b>{{ Fmt::date($scale->inspection_due_on) ?: '—' }}</b></div>
        <div><span>Seal number</span><b>{{ $scale->wm_seal_ref ?: '—' }}</b></div>
        <div><span>Reported to W&amp;M</span><b>{{ Fmt::date($scale->wm_reported_on) ?: '—' }}</b></div>
        <div><span>Sealed on</span><b>{{ Fmt::date($scale->wm_sealed_on) ?: '—' }}</b></div>
        <div><span>Calibration due</span><b>{{ Fmt::date($scale->calibration_due_on) ?: '—' }}</b></div>
    </div>
    @if ($p = $scale->problems())
        <div class="pb-alert pb-alert-err" style="margin-top:12px"><b>Production will not accept this scale:</b>@foreach ($p as $m)<div>{{ $m }}</div>@endforeach</div>
    @elseif ($st === 'due_soon')
        <div class="pb-alert pb-alert-warn" style="margin-top:12px">Inspection due {{ Fmt::date($scale->inspection_due_on) }}. Book Weights &amp; Measures now.</div>
    @endif
</section>

<section class="card">
    <div class="card-head"><h3>Inspections &amp; certificates</h3></div>
    <div class="table-wrap"><table class="pb-table pb-table-cards">
        <thead><tr><th>Date</th><th>What</th><th>By</th><th>Result</th><th>Seal #</th><th>Next due</th><th>Certificate</th><th>Recorded</th><th></th></tr></thead>
        <tbody>
        @forelse ($scale->inspections as $in)
            <tr @class(['pb-void' => $in->isVoid()])>
                <td data-th="Date">{{ Fmt::date($in->inspected_on) }} @if ($in->isVoid())<span class="pill bad">VOID</span>@endif</td>
                <td data-th="What">{{ ScaleInspection::KINDS[$in->kind] ?? $in->kind }}</td>
                <td data-th="By">{{ $in->inspector }}</td>
                <td data-th="Result"><span class="pill {{ $in->result === 'failed' ? 'bad' : (str_starts_with($in->result, 'passed') ? 'ok' : '') }}">{{ ScaleInspection::RESULTS[$in->result] ?? $in->result }}</span></td>
                <td data-th="Seal #">{{ $in->seal_number }}</td>
                <td data-th="Next due">{{ Fmt::date($in->next_due_on) }}</td>
                <td data-th="Certificate">@foreach ($in->attachments as $a)<a href="{{ route('files.download', $a) }}">{{ $a->filename }}</a>@endforeach</td>
                <td data-th="Recorded">{{ $in->recorder?->name }} · {{ Fmt::dt($in->created_at) }}</td>
                <td>@if (! $in->isVoid() && $canEdit)
                    <details class="pb-details pb-details-danger"><summary>Void</summary>
                        <form method="POST" action="{{ route('scales.inspection.void', $in) }}" class="pb-form">@csrf
                            <input name="reason" class="pb-input" required placeholder="Reason (required)"><button class="pb-btn pb-danger" type="submit">Void</button></form></details>
                @elseif ($in->isVoid())<small class="pb-muted">{{ $in->void_reason }}</small>@endif</td></tr>
        @empty
            <tr><td colspan="9" class="pb-muted">No inspections recorded yet.</td></tr>
        @endforelse
        </tbody></table></div>
    @if ($canEdit)
    <details class="pb-details" @if ($scale->inspections->isEmpty()) open @endif>
        <summary>Record an inspection, report or calibration</summary>
        <form method="POST" action="{{ route('scales.inspect', $scale) }}" enctype="multipart/form-data" class="pb-form">@csrf
            <div class="pb-grid">
                <div class="f-field"><label for="i_kind">What happened</label><select id="i_kind" name="kind" class="pb-select" required>@foreach (ScaleInspection::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="f-field"><label for="i_on">Date</label><input id="i_on" type="date" name="inspected_on" class="pb-input" required max="{{ now()->timezone(config('pbp.site_timezone'))->toDateString() }}"></div>
                <div class="f-field"><label for="i_by">Inspector / agency</label><input id="i_by" name="inspector" class="pb-input" placeholder="e.g. Suffolk County W&amp;M"></div>
                <div class="f-field"><label for="i_res">Result</label><select id="i_res" name="result" class="pb-select" required>@foreach (ScaleInspection::RESULTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="f-field"><label for="i_seal">Seal number</label><input id="i_seal" name="seal_number" class="pb-input"></div>
                <div class="f-field"><label for="i_due">Next due</label><input id="i_due" type="date" name="next_due_on" class="pb-input"><small class="pb-muted">Empty = date + {{ Equipment::inspectionMonths() }} months (inspections).</small></div>
                <div class="f-field"><label for="i_cert">Certificate (PDF or photo)</label><input id="i_cert" type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp"></div>
                <div class="f-field"><label for="i_notes">Notes</label><input id="i_notes" name="notes" class="pb-input"></div>
            </div>
            <div><button class="btn-save" type="submit">Save inspection</button></div>
        </form>
    </details>
    @endif
</section>

<section class="card pb-form-card">
    <div class="card-head"><h3>Scale details</h3></div>
    <form method="POST" action="{{ route('scales.update', $scale) }}" class="pb-form">@csrf @method('PUT')
        @include('scales._fields', ['disabled' => ! $canEdit])
        @if ($canEdit)<div><button class="btn-save" type="submit">Save</button></div>@endif
    </form>
</section>

<section class="card">
    <div class="card-head"><h3>Change history</h3><span class="pb-muted">Every change is kept (audit log).</span></div>
    <ul class="pb-history">
        @forelse ($history as $h)
            <li><b>{{ Fmt::dt($h->created_at) }}</b> · {{ $h->user?->name ?? 'System' }} · {{ $h->action }}
                @foreach ((array) ($h->details['new'] ?? []) as $k => $nv)
                    @continue(in_array($k, ['id', 'recorded_by_user_id', 'retention_until'], true))
                    <div class="pb-muted">{{ str_replace('_', ' ', $k) }}: @if (array_key_exists($k, (array) ($h->details['old'] ?? [])))<del>{{ is_scalar($h->details['old'][$k]) ? $h->details['old'][$k] : json_encode($h->details['old'][$k]) }}</del> → @endif{{ is_scalar($nv) ? (is_bool($nv) ? ($nv ? 'yes' : 'no') : $nv) : json_encode($nv) }}</div>
                @endforeach</li>
        @empty
            <li class="pb-muted">No changes yet.</li>
        @endforelse
    </ul>
</section>
@endsection
