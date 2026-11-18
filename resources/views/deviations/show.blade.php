@extends('layouts.app')
@section('title', $d->public_id)
@section('page_title', $d->public_id.' · '.$d->title)
@section('parent_title', 'Deviations')
@section('parent_url', route('deviations.index'))
@php use App\Support\Fmt; $ro = auth()->user()->isViewer() || in_array($d->status, ['closed', 'void'], true); @endphp
@section('content')
<section class="card">
    <div class="card-head"><span class="pill {{ Fmt::pill($d->status) }}">{{ \App\Models\Deviation::STATUSES[$d->status] ?? $d->status }}</span>
        @if ($d->requires_qc_approval)<span class="pill blue">QC approval needed to continue</span>@endif
        @if ($d->active_ingredient)<span class="pill warn">Active ingredient</span>@endif
        @include('partials.rec.filing', ['record' => $d])</div>
    <div class="pb-kv">
        @if ($d->batch)<div><span>Batch</span><b><a class="pb-bn" href="{{ route('production.show', $d->batch) }}">{{ $d->batch->batch_number }}</a></b></div>@endif
        @if ($d->lot)<div><span>Lot</span><b>{{ $d->lot->public_id }}</b></div>@endif
        <div><span>Opened</span><b>{{ Fmt::dt($d->created_at) }}</b></div>
        @if ($d->closed_at)<div><span>Closed</span><b>{{ Fmt::dt($d->closed_at) }}</b></div>@endif
    </div>
    <p style="margin-top:10px">{{ $d->description }}</p>
    @foreach (['impact' => 'Impact', 'root_cause' => 'Root cause', 'corrective_action' => 'Corrective action', 'disposition' => 'Disposition'] as $f => $l)
        @if ($d->{$f})<p><b>{{ $l }}:</b> {{ $d->{$f} }}</p>@endif
    @endforeach
    @include('partials.rec.signatures', ['sigs' => $d->signatures])
    @include('partials.rec.revisions', ['revisions' => $d->revisions])
</section>
@unless ($ro)
<section class="card">
    @if ($d->requires_qc_approval && $d->status === 'open')
        <form method="POST" action="{{ route('deviations.approve', $d) }}" class="pb-form">@csrf
            <div class="card-head"><h3>QC: approve continuing</h3></div>
            @include('partials.rec.sign', ['label' => 'QC reviewer', 'only' => $qcReviewers])
            <div><button class="btn-save" type="submit">Approve continuing</button></div></form>
    @endif
    <form method="POST" action="{{ route('deviations.close', $d) }}" class="pb-form" style="margin-top:14px">@csrf
        <div class="card-head"><h3>Close (QC)</h3></div>
        <div class="f-field"><label>Impact</label><textarea name="impact" rows="2">{{ $d->impact }}</textarea></div>
        <div class="f-field"><label>Root cause</label><textarea name="root_cause" rows="2" required>{{ $d->root_cause }}</textarea></div>
        <div class="f-field"><label>Corrective action</label><textarea name="corrective_action" rows="2" required>{{ $d->corrective_action }}</textarea></div>
        <div class="f-field"><label>Disposition</label><textarea name="disposition" rows="2" required>{{ $d->disposition }}</textarea></div>
        @include('partials.rec.sign', ['label' => 'Closed by (QC reviewer)', 'only' => $qcReviewers])
        <div><button class="btn-save" type="submit">Close deviation</button></div></form>
    @include('partials.rec.correct', ['record' => $d, 'type' => 'deviation', 'fields' => ['description' => 'Description', 'impact' => 'Impact', 'root_cause' => 'Root cause', 'corrective_action' => 'Corrective action', 'disposition' => 'Disposition']])
    @include('partials.rec.void', ['action' => route('records.void', ['deviation', $d->id]), 'what' => 'deviation'])
</section>
@endunless
@endsection
