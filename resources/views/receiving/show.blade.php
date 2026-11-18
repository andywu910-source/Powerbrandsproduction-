@extends('layouts.app')
@section('title', $lot->public_id)
@section('page_title', $lot->public_id)
@section('parent_title', 'Receiving')
@section('parent_url', route('receiving.index'))
@php use App\Support\Fmt; $ro = auth()->user()->isViewer(); $sup = $signers->filter->isSupervisorOrAdmin(); @endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>{{ $lot->ingredient->name }}</h3>
        <span class="pill {{ Fmt::pill($lot->status) }}">{{ \App\Models\MaterialLot::STATUSES[$lot->status] ?? $lot->status }}</span>
        @include('partials.rec.filing', ['record' => $lot])</div>
    <div class="pb-kv">
        <div><span>Supplier</span><b>{{ $lot->supplier->name }}</b></div><div><span>Supplier lot</span><b>{{ $lot->supplier_lot ?: '—' }}</b></div>
        <div><span>Received</span><b>{{ Fmt::dt($lot->received_at) }}</b></div><div><span>Quantity received</span><b>{{ Fmt::qty($lot->received_qty, $lot->unit) }}</b></div>
        <div><span>On hand</span><b>{{ Fmt::qty($lot->current_balance, $lot->unit) }}</b></div><div><span>Reserved for batches</span><b>{{ Fmt::qty($lot->openReservedQty(), $lot->unit) }}</b></div>
        <div><span>Metrc manifest / UID</span><b>{{ $lot->metrc_manifest_no ?: '—' }} / {{ $lot->metrc_package_uid ?: '—' }}</b></div>
        <div><span>Expires</span><b>{{ Fmt::date($lot->expiration_date) ?: '—' }}</b></div>
        <div><span>Condition / seals</span><b>{{ $lot->condition_ok ? 'OK' : 'Not OK' }} / {{ $lot->seals_ok ? 'OK' : 'Not OK' }}</b></div>
        <div><span>COA</span><b>{{ $lot->coa_result ? strtoupper($lot->coa_result) : '—' }}{{ $lot->potency_pct ? ' · '.$lot->potency_pct.'%' : '' }}</b></div>
    </div>
    @if ($lot->condition_notes)<p class="pb-muted">{{ $lot->condition_notes }}</p>@endif
    @include('partials.rec.signatures', ['sigs' => $lot->signatures])
    @include('partials.rec.revisions', ['revisions' => $lot->revisions])
</section>

@if ($lot->status === 'quarantine' && ! $ro)
<section class="card">
    <div class="card-head"><h3>Quarantine → QC release</h3></div>
    @if (! $lot->hasValidSignature('verified'))
        <form method="POST" action="{{ route('receiving.verify', $lot) }}" class="pb-inline-form">@csrf
            @include('partials.rec.sign', ['label' => 'Receipt checked by (a different person)'])<button class="pb-btn pb-btn-ghost" type="submit">Verify receipt</button></form>
    @endif
    <h4 style="margin-top:14px">Paperwork</h4>
    @include('partials.rec.attachments', ['files' => $lot->attachments, 'upload' => route('receiving.upload', $lot),
        'kinds' => ['coa' => 'COA', 'attestation' => 'Supplier attestation', 'source_records' => 'Source / Metrc records', 'other' => 'Other']])
    <form method="POST" action="{{ route('receiving.paperwork', $lot) }}" class="pb-form" style="margin-top:12px">@csrf
        <div class="pb-grid">
            <div class="f-field"><label>COA result</label><select name="coa_result" class="pb-select"><option value="">—</option><option value="pass" @selected($lot->coa_result === 'pass')>Pass</option><option value="fail" @selected($lot->coa_result === 'fail')>Fail</option></select></div>
            <div class="f-field"><label>Potency on COA (%)</label><input class="pb-input" type="number" step="0.001" name="potency_pct" value="{{ $lot->potency_pct }}"></div>
        </div>
        <label class="pb-check-inline"><input type="checkbox" name="attestation_ok" value="1" @checked($lot->attestation_ok)> Supplier attestation checked (terpenes / flavors)</label>
        <label class="pb-check-inline"><input type="checkbox" name="source_records_ok" value="1" @checked($lot->source_records_ok)> Source records match (Metrc)</label>
        <label class="pb-check-inline"><input type="checkbox" name="banned_ingredient_ok" value="1" @checked($lot->banned_ingredient_ok)> No banned ingredients</label>
        <label class="pb-check-inline"><input type="checkbox" name="inhaled_allowed_ok" value="1" @checked($lot->inhaled_allowed_ok)> On the inhaled allowed list (if inhaled)</label>
        @include('partials.rec.sign', ['label' => 'Paperwork checked by'])
        <div><button class="pb-btn pb-btn-ghost" type="submit">Save paperwork check</button></div>
    </form>
    @include('partials.rec.problems', ['problems' => $releaseProblems])
    <form method="POST" action="{{ route('receiving.release', $lot) }}" class="pb-form" style="margin-top:12px">@csrf
        @include('partials.rec.sign', ['label' => 'QC reviewer', 'only' => $qcReviewers])
        <div class="f-field"><label>Note</label><input class="pb-input" name="note"></div>
        <div class="pb-row"><button class="btn-save" type="submit" @disabled($releaseProblems)>Release lot</button>
            <button class="btn-save pb-danger" type="submit" formaction="{{ route('receiving.reject', $lot) }}">Reject lot</button></div>
    </form>
</section>
@else
<section class="card"><div class="card-head"><h3>Files</h3></div>
    @include('partials.rec.attachments', ['files' => $lot->attachments, 'upload' => $ro ? null : route('receiving.upload', $lot),
        'kinds' => ['coa' => 'COA', 'attestation' => 'Supplier attestation', 'source_records' => 'Source / Metrc records', 'other' => 'Other']])</section>
@endif

<section class="card">
    <div class="card-head"><h3>Stock ledger</h3><span class="pb-muted">Every movement in and out. The balance can never go below zero.</span></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>When</th><th>Type</th><th>Change</th><th>Balance</th><th>Reason / batch</th><th>By</th></tr></thead>
        <tbody>
        @forelse ($lot->ledger as $e)
            <tr><td>{{ Fmt::dt($e->created_at) }}</td><td>{{ ucfirst(str_replace("_", " ", $e->entry_type)) }}</td><td>{{ $e->qty_delta > 0 ? '+' : '' }}{{ Fmt::qty($e->qty_delta, $lot->unit) }}</td>
                <td>{{ Fmt::qty($e->balance_after, $lot->unit) }}</td><td>{{ $e->reason }}</td><td>{{ $e->user?->name }}</td></tr>
        @empty
            <tr><td colspan="6" class="pb-muted">No movements yet (stock counts once QC releases the lot).</td></tr>
        @endforelse
        </tbody></table></div>
    @if (in_array($lot->status, ['released', 'depleted'], true) && auth()->user()->isSupervisorOrAdmin())
        <details class="pb-details"><summary>Record a stock change (waste, count correction)</summary>
            <form method="POST" action="{{ route('receiving.adjust', $lot) }}" class="pb-form">@csrf
                <div class="pb-grid-3">
                    <div class="f-field"><label>Type</label><select name="type" class="pb-select"><option value="waste">Waste (take out)</option><option value="adjust_down">Count correction – less</option><option value="adjust_up">Count correction – more</option><option value="return">Returned to supplier</option></select></div>
                    <div class="f-field"><label>Amount</label><input class="pb-input" type="number" step="0.0001" min="0" name="qty" required></div>
                    <div class="f-field"><label>Unit</label><select name="unit" class="pb-select">@foreach ($units as $k => $l)<option value="{{ $k }}" @selected($k === $lot->unit)>{{ $l }}</option>@endforeach</select></div>
                </div>
                <div class="f-field"><label>Reason</label><input class="pb-input" name="reason" required></div>
                @include('partials.rec.sign', ['label' => 'Recorded by (supervisor)', 'only' => $sup])
                <div><button class="pb-btn pb-btn-ghost" type="submit">Record</button></div></form></details>
    @endif
    @include('partials.rec.void', ['action' => route('records.void', ['material_lot', $lot->id]), 'what' => 'lot'])
</section>
@endsection
