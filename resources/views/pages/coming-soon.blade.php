@extends('layouts.app')
@section('title', $title)
@section('page_title', $title)

@section('content')
<section class="card pb-soon-card">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
    <h3>{{ $title }}: coming soon</h3>
    <p class="pb-muted">{{ $blurb }}</p>
    @if ($section === 'labels')
        <div class="pb-label-mock" aria-label="3 by 2 inch label outline">
            <span>3″ × 2″ label</span>
            <b>Template coming</b>
            <small>Waiting on Andy's sample label</small>
        </div>
    @else
        <p class="pb-muted">This section is being built and will appear here once it's ready.</p>
    @endif
</section>
@endsection
