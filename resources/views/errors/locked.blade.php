@extends('layouts.app')
@section('title', 'Not allowed yet')
@section('page_title', 'Not allowed yet')
@section('content')
<section class="card pb-soon-card">
    <svg viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
    <h3>This step is locked</h3>
    @foreach ((array) ($reasons ?? []) as $m)<p>{{ $m }}</p>@endforeach
    <p class="pb-muted">Steps must be done in order. This applies to everyone, admins included.</p>
    <p><a class="pb-btn pb-btn-ghost" href="{{ url()->previous() }}">Go back</a></p>
</section>
@endsection
