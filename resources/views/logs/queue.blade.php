@extends('layouts.app')
@section('title', 'Needs verification')
@section('page_title', 'Compliance')
@push('styles')<link rel="stylesheet" href="{{ asset('css/logs.css') }}?v={{ @filemtime(public_path('css/logs.css')) }}">@endpush
@php use App\Support\Fmt; @endphp
@section('content')
<p class="lg-back"><a href="{{ route('logs.index') }}">‹ All logs</a></p>
<section class="card" id="lgQueue">
    <div class="card-head"><h3>Needs verification</h3><span class="pill warn" id="lgQueueCount">{{ $entries->total() }}</span><span class="pb-spacer"></span>
        <span class="pb-muted">{{ $canVerify ? 'Open an entry to verify it or send it back. You cannot verify your own entries.' : 'An admin verifies each entry.' }}</span></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>#</th><th>Log</th><th>Entry date</th><th>Entered by</th><th>Recorded at</th><th></th></tr></thead>
        <tbody>
        @forelse ($entries as $e)
            <tr>
                <td>{{ $e->id }}</td>
                <td><span class="lg-code">{{ $e->definition->code }}</span> {{ $e->definition->name }}</td>
                <td class="pb-nowrap">{{ $e->occurred_on?->format('M j, Y') }}</td>
                <td>{{ $e->enteredBy?->name }}</td>
                <td class="pb-nowrap">{{ Fmt::dt($e->created_at) }}</td>
                <td class="pb-nowrap"><a class="pb-btn btn-sm" href="{{ route('logs.show', [$e->definition, 'entry' => $e->id]) }}#lgDetail">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="pb-muted">Nothing is waiting for verification.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $entries->links() }}
</section>
@endsection
