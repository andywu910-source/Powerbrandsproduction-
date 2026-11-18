@extends('layouts.app')
@section('title', 'Deviations')
@section('page_title', 'Deviations')
@php use App\Support\Fmt; @endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>{{ request('status', 'open') === 'open' ? 'Open deviations' : 'All deviations' }}</h3><span class="pb-spacer"></span>
        <a class="pb-btn pb-btn-ghost" href="{{ route('deviations.index', ['status' => request('status', 'open') === 'open' ? 'all' : 'open']) }}">{{ request('status', 'open') === 'open' ? 'Show all' : 'Show open only' }}</a></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>ID</th><th>Title</th><th>Batch</th><th>Opened</th><th>Status</th></tr></thead>
        <tbody>@forelse ($devs as $d)
            <tr><td><a href="{{ route('deviations.show', $d) }}"><b>{{ $d->public_id }}</b></a></td><td>{{ $d->title }}</td>
                <td>@if ($d->batch)<span class="pb-bn">{{ $d->batch->batch_number }}</span>@endif</td><td>{{ Fmt::dt($d->created_at) }}</td>
                <td><span class="pill {{ Fmt::pill($d->status) }}">{{ \App\Models\Deviation::STATUSES[$d->status] ?? $d->status }}</span></td></tr>
        @empty<tr><td colspan="5" class="pb-muted">None.</td></tr>@endforelse</tbody></table></div>
    {{ $devs->links() }}
</section>
@endsection
