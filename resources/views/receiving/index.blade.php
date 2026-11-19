@extends('layouts.app')
@section('title', 'Receiving')
@section('page_title', 'Receiving')
@php use App\Support\Fmt; @endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>Received lots</h3><span class="pb-spacer"></span>
        @unless (auth()->user()->isViewer())<a class="btn-save" href="{{ route('receiving.create') }}">Receive a delivery</a>@endunless</div>
    <form method="GET" class="pb-inline-form">
        <div class="f-field"><label for="rc_q">Search</label><div class="pb-scan-wrap"><input id="rc_q" class="pb-input" name="q" value="{{ request('q') }}" placeholder="Lot, supplier lot or Metrc UID" data-scan="search" data-scan-submit>@include('partials.scan', ['for' => 'rc_q', 'label' => 'search'])</div></div>
        <div class="f-field"><label>Status</label><select name="status" class="pb-select"><option value="">All</option>
            @foreach (\App\Models\MaterialLot::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="f-field"><label>Material</label><select name="ingredient_id" class="pb-select"><option value="">All</option>
            @foreach ($ingredients as $g)<option value="{{ $g->id }}" @selected((int) request('ingredient_id') === $g->id)>{{ $g->name }}</option>@endforeach</select></div>
        <button class="pb-btn pb-btn-ghost">Filter</button>
    </form>
    <div class="table-wrap" style="margin-top:12px"><table class="pb-table">
        <thead><tr><th>Lot</th><th>Material</th><th>Supplier / lot</th><th>Received</th><th>Qty in</th><th>On hand</th><th>Expires</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($lots as $l)
            <tr class="{{ $l->voided_at ? 'is-void' : '' }}"><td><a href="{{ route('receiving.show', $l) }}"><b>{{ $l->public_id }}</b></a></td><td>{{ $l->ingredient?->name }}</td>
                <td>{{ $l->supplier?->name }}<br><span class="pb-muted">{{ $l->supplier_lot }}</span></td><td>{{ Fmt::date($l->received_at) }}</td>
                <td>{{ Fmt::qty($l->received_qty, $l->unit) }}</td><td>{{ Fmt::qty($l->current_balance, $l->unit) }}</td><td>{{ Fmt::date($l->expiration_date) }}</td>
                <td><span class="pill {{ Fmt::pill($l->status) }}">{{ \App\Models\MaterialLot::STATUSES[$l->status] ?? $l->status }}</span></td></tr>
        @empty
            <tr><td colspan="8" class="pb-muted">Nothing received yet.</td></tr>
        @endforelse
        </tbody></table></div>
    {{ $lots->links() }}
</section>
@endsection
