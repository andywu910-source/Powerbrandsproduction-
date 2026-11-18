@extends('layouts.app')
@section('title', 'Setup')
@section('page_title', 'Products & people')
@section('content')
<div class="pb-tiles">
    @foreach ($resources as $key => [$title])
        <a class="card pb-tile" href="{{ route('setup.index', $key) }}"><b>{{ $title }}</b>
            <span class="pb-muted">{{ (new ($resources[$key][1]))->newQuery()->count() }} on file</span></a>
    @endforeach
    @php $sc = \App\Models\Equipment::scales()->where('active', true)->get(); $scBad = $sc->filter(fn ($s) => in_array($s->inspectionStatus(), ['overdue', 'missing'], true))->count(); $scSoon = $sc->filter(fn ($s) => $s->inspectionStatus() === 'due_soon')->count(); @endphp
    <a class="card pb-tile" href="{{ route('scales.index') }}"><b>Scale register</b><span class="pb-muted pb-scale-block-line">{{ \App\Services\ScaleBlock::statusLine() }}</span><span class="pb-muted">{{ $sc->count() }} active scales @if ($scBad)<span class="pill bad">{{ $scBad }} overdue</span>@endif @if ($scSoon)<span class="pill warn">{{ $scSoon }} due soon</span>@endif</span></a>
    <a class="card pb-tile" href="{{ route('plans.index') }}"><b>Recipes (MMP) &amp; quality plans (PQP)</b><span class="pb-muted">Approved by QC before any batch</span></a>
    @if (auth()->user()->isAdmin())
        <a class="card pb-tile" href="{{ route('process.index') }}"><b>Manufacturing stages by family</b><span class="pb-muted">Prerolls, vapes, flower, rosin, Green Mantis</span></a>
        <a class="card pb-tile" href="{{ route('settings.edit') }}"><b>Settings</b><span class="pb-muted">Batch numbers, sign-off, QC reviewers, tolerances</span></a>
    @endif
</div>
@endsection
