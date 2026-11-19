@extends('layouts.app')
@section('title', 'Related logs')
@section('page_title', 'Compliance')
@push('styles')<link rel="stylesheet" href="{{ asset('css/logs.css') }}?v={{ @filemtime(public_path('css/logs.css')) }}">@endpush
@section('content')
<p class="lg-back"><a href="{{ route('logs.index') }}">‹ All logs</a></p>
<section class="card">
    <div class="card-head"><h3>Every log entry for {{ \App\Models\LogEntryLink::KINDS[$kind] }}: <span class="lg-code">{{ $kind === 'employee' ? \Illuminate\Support\Str::title($value) : $value }}</span></h3></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>Date</th><th>Log</th><th>Recorded by</th><th>Recorded at</th></tr></thead>
        <tbody>@forelse ($entries as $e)
            <tr><td>{{ $e->occurred_on?->format('M j, Y') }}</td>
                <td><a href="{{ route('logs.show', [$e->definition, 'entry' => $e->id]) }}"><span class="lg-code">{{ $e->definition->code }}</span> {{ $e->definition->name }}</a></td>
                <td>{{ $e->enteredBy?->name }}</td><td>{{ \App\Support\Fmt::dt($e->created_at) }}</td></tr>
        @empty<tr><td colspan="4" class="pb-muted">Nothing found.</td></tr>@endforelse</tbody>
    </table></div>
</section>
@endsection
