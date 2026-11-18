@extends('layouts.app')
@section('title', $p->public_id)
@section('page_title', $p->public_id.' · '.$p->title)
@section('parent_title', 'Recipes & plans')
@section('parent_url', route('plans.index'))
@php $edit = $p->status === 'draft' && auth()->user()->isSupervisorOrAdmin(); @endphp
@section('content')
<section class="card pb-form-card">
    <div class="card-head"><span class="pill {{ \App\Support\Fmt::pill($p->status) }}">{{ ucfirst($p->status) }}</span> <span>v{{ $p->version }} · {{ \App\Models\Product::FAMILIES[$p->product_type] ?? '' }}</span></div>
    <form method="POST" action="{{ route('plans.pqp.update', $p) }}" class="pb-form">@csrf @method('PUT')
        <div class="f-field"><label>Title</label><input class="pb-input" name="title" value="{{ $p->title }}" @disabled(! $edit)></div>
        <div class="f-field"><label>Effective date</label><input class="pb-input" type="date" name="effective_date" value="{{ optional($p->effective_date)->format('Y-m-d') ?? $p->effective_date }}" @disabled(! $edit)></div>
        <div class="f-field"><label>Plan (specs, tests, acceptance limits, release criteria)</label><textarea name="body" rows="12" @disabled(! $edit)>{{ $p->body }}</textarea></div>
        @if ($edit)<div><button class="btn-save" type="submit">Save draft</button></div>@endif
    </form>
    @include('partials.rec.signatures', ['sigs' => $p->signatures])
    @if ($edit)
        <form method="POST" action="{{ route('plans.pqp.approve', $p) }}" class="pb-form" style="margin-top:12px">@csrf
            @include('partials.rec.sign', ['label' => 'Approved by (QC reviewer)', 'only' => $signers->filter->isQcReviewer()])
            <div><button class="btn-save" type="submit">Approve plan</button></div></form>
    @endif
</section>
@endsection
