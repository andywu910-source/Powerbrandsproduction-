@extends('layouts.app')
@section('title', 'No access')
@section('page_title', 'No access')
@section('content')
<section class="card pb-soon-card">
    <svg viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
    <h3>You don't have access to this page</h3>
    <p class="pb-muted">Ask an admin if you think you should.</p>
    <p><a class="pb-btn pb-btn-ghost" href="{{ route('dashboard') }}">Back to dashboard</a></p>
</section>
@endsection
