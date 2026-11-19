@extends('layouts.app')
@section('title', 'Deviations')
@section('page_title', 'Deviations')
@php use App\Support\Fmt; @endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>{{ request('status', 'open') === 'open' ? 'Open deviations' : 'All deviations' }}</h3><span class="pb-spacer"></span>
        <a class="pb-btn pb-btn-ghost" href="{{ route('deviations.index', ['status' => request('status', 'open') === 'open' ? 'all' : 'open']) }}">{{ request('status', 'open') === 'open' ? 'Show all' : 'Show open only' }}</a></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>ID</th><th>Title</th><th>Source</th><th>Batch</th><th>Opened</th><th>Status</th></tr></thead>
        <tbody>@forelse ($devs as $d)
            <tr><td><a href="{{ route('deviations.show', $d) }}"><b>{{ $d->public_id }}</b></a></td><td>{{ $d->title }}</td>
                <td>{{ $d->originLabel() }}@if ($d->origin_ref)<br><span class="pb-muted">{{ $d->origin_ref }}</span>@endif</td>
                <td>@if ($d->batch)<span class="pb-bn">{{ $d->batch->batch_number }}</span>@endif</td><td>{{ Fmt::dt($d->created_at) }}</td>
                <td><span class="pill {{ Fmt::pill($d->status) }}">{{ \App\Models\Deviation::STATUSES[$d->status] ?? $d->status }}</span></td></tr>
        @empty<tr><td colspan="6" class="pb-muted">None.</td></tr>@endforelse</tbody></table></div>
    {{ $devs->links() }}
</section>
@if ($canOpen)
<section class="card" id="devNew">
    <details class="pb-details" @if (request('new') || $errors->any()) open @endif><summary><b>Open a deviation / CAPA</b> <span class="pb-muted">(complaint, audit, OCM finding, or anything not started from a batch)</span></summary>
        <form method="POST" action="{{ route('deviations.store') }}" class="pb-form">@csrf
            <div class="pb-grid-3">
                <div class="f-field"><label for="devOriginSel">Source <span class="pb-muted">(optional)</span></label>
                    <select id="devOriginSel" name="origin" class="pb-select"><option value="">—</option>
                        @foreach (\App\Models\Deviation::ORIGINS as $k => $l)<option value="{{ $k }}" @selected(old('origin', request('origin')) === $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="f-field"><label for="devOriginRef">Source reference <span class="pb-muted">(e.g. CMP entry #12 or a Metrc tag)</span></label>
                    <div class="pb-scan-wrap"><input id="devOriginRef" class="pb-input" name="origin_ref" maxlength="120" value="{{ old('origin_ref', request('origin_ref')) }}" data-scan="fill">@include('partials.scan', ['for' => 'devOriginRef', 'label' => 'source reference'])</div></div>
                <div class="f-field"><label for="devBatch">Batch # <span class="pb-muted">(optional)</span></label>
                    <div class="pb-scan-wrap"><input id="devBatch" class="pb-input" name="batch_number" maxlength="60" value="{{ old('batch_number') }}" data-scan="fill">@include('partials.scan', ['for' => 'devBatch', 'label' => 'batch number'])</div>
                    @error('batch_number')<small class="pb-err">{{ $message }}</small>@enderror</div>
            </div>
            <div class="f-field"><label>Short title</label><input class="pb-input" name="title" required value="{{ old('title') }}"></div>
            <div class="f-field"><label>What happened</label><textarea name="description" rows="3" required>{{ old('description') }}</textarea></div>
            <label class="pb-check-inline"><input type="checkbox" name="active_ingredient" value="1"> Involves the active (cannabis) ingredient</label>
            <label class="pb-check-inline"><input type="checkbox" name="requires_qc_approval" value="1"> QC must approve before work continues</label>
            @include('partials.rec.sign', ['label' => 'Reported by'])
            <div><button class="btn-save" type="submit">Open deviation</button></div>
        </form>
        <p class="pb-muted">Root cause, corrective and preventive action, disposition and the effectiveness check are filled in when QC closes it.</p>
    </details>
</section>
@endif
@endsection
