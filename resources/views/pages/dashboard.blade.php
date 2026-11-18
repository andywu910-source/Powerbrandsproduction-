@extends('layouts.app')
@section('title', 'Dashboard')
@section('page_title', 'Dashboard')
@php use App\Support\Fmt; use App\Support\Modules; @endphp

@section('content')
<section class="card">
    <div class="card-head"><h3>Welcome, {{ auth()->user()->name }}</h3>
        @if (auth()->user()->isViewer())<span class="pill">Read-only account</span>@endif</div>
    <div class="pb-stats">
        @if (Modules::enabled('checklists'))
        <div class="pb-stat {{ $overdue->count() ? 'bad' : '' }}"><span>Checklists overdue</span><b class="pb-big-num">{{ $overdue->count() }}</b></div>
        <div class="pb-stat"><span>Checklists due</span><b class="pb-big-num">{{ $due->count() }}</b></div>
        @endif
        <div class="pb-stat"><span>Batches in progress</span><b class="pb-big-num">{{ $batches->count() }}</b></div>
        <div class="pb-stat {{ $quarantine->count() ? 'warn' : '' }}"><span>Lots in quarantine</span><b class="pb-big-num">{{ $quarantine->count() }}</b></div>
        <div class="pb-stat {{ $deviations->count() ? 'warn' : '' }}"><span>Open deviations</span><b class="pb-big-num">{{ $deviations->count() }}</b></div>
        <div class="pb-stat"><span>Waiting to file to Drive</span><b class="pb-big-num">{{ $unfiled }}</b></div>
        @php $scaleBad = $scaleAlerts->filter(fn ($s) => $s->inspectionStatus() !== 'due_soon')->count(); @endphp
        <div class="pb-stat {{ $scaleBad ? 'bad' : ($scaleAlerts->count() ? 'warn' : '') }}"><span>Scales needing inspection</span><b class="pb-big-num">{{ $scaleAlerts->count() }}</b></div>
    </div>
</section>

@if (Modules::enabled('checklists'))
<section class="card">
    <div class="card-head"><h3>Checklists due &amp; overdue</h3><span class="pb-spacer"></span><a class="pb-btn pb-btn-ghost" href="{{ route('checklists.index') }}">All checklists</a></div>
    @if ($overdue->isEmpty() && $due->isEmpty())
        <p class="pb-muted">Nothing due right now.</p>
    @else
    <ul class="pb-list">
        @foreach ($overdue->concat($due) as $c)
            @php $st = $c->displayStatus(); @endphp
            <li><span class="pill {{ Fmt::pill($st) }}">{{ ucfirst(str_replace('_', ' ', $st)) }}</span>
                <a href="{{ route('checklists.show', $c) }}"><b>{{ $c->template?->name }}</b></a>
                <span class="pb-muted">{{ $c->scope_label ?? '' }} · due {{ Fmt::dt($c->due_at) }}</span></li>
        @endforeach
    </ul>
    @endif
</section>
@endif

<div class="grid-2 eq">
    @if (Modules::enabled('production'))
    <section class="card">
        <div class="card-head"><h3>Batches in progress</h3><span class="pb-spacer"></span><a class="pb-btn pb-btn-ghost" href="{{ route('production.index') }}">Production</a></div>
        <ul class="pb-list">
            @forelse ($batches as $b)
                <li><a class="pb-bn" href="{{ route('production.show', $b) }}">{{ $b->batch_number }}</a>
                    <span>{{ $b->product?->name }}</span><span class="pb-spacer"></span>
                    <span class="pill {{ Fmt::pill($b->status) }}">Step {{ min($b->current_step, 9) }} of 9</span></li>
            @empty
                <li class="pb-muted">No batches in progress.</li>
            @endforelse
        </ul>
    </section>
    @endif
    @if ($scaleBlock && ($scaleBlock['missing']->count() || $scaleBlock['overdue']->count() || $scaleBlock['total'] === 0))
    <section class="card pb-alert-card warn" id="scaleBlockWarning">
        <div class="card-head"><h3>Scale inspection block is off</h3><span class="pb-spacer"></span>
            <a class="pb-btn pb-btn-ghost" href="{{ route('scales.index') }}">Setup › Scale register</a></div>
        <p>{{ $scaleBlock['line'] }}</p>
        @if ($scaleBlock['total'] === 0)<p class="pb-muted">No active scales in the scale register yet.</p>@endif
        <ul class="pb-list">
            @foreach ($scaleBlock['missing'] as $s)
                <li><span class="pill bad">No inspection date</span><a href="{{ route('scales.show', $s) }}">{{ $s->equip_id }} · {{ $s->name }}</a></li>
            @endforeach
            @foreach ($scaleBlock['overdue'] as $s)
                <li><span class="pill bad">Overdue</span><a href="{{ route('scales.show', $s) }}">{{ $s->equip_id }} · {{ $s->name }}</a><span class="pb-muted">due {{ Fmt::date($s->inspection_due_on) }}</span></li>
            @endforeach
        </ul>
    </section>
    @endif
    <section class="card">
        <div class="card-head"><h3>Needs attention</h3></div>
        <ul class="pb-list">
            @foreach ($quarantine->take(8) as $l)
                <li><span class="pill warn">Quarantine</span>
                    @if (Modules::enabled('receiving'))<a href="{{ route('receiving.show', $l) }}">{{ $l->public_id }}</a>@else{{ $l->public_id }}@endif
                    <span>{{ $l->ingredient?->name }}</span><span class="pb-muted">received {{ Fmt::date($l->received_at) }}</span></li>
            @endforeach
            @foreach ($deviations->take(8) as $d)
                <li><span class="pill warn">Deviation</span><a href="{{ route('deviations.show', $d) }}">{{ $d->public_id }}</a><span>{{ $d->title }}</span></li>
            @endforeach
            @foreach ($scaleAlerts as $s)
                @php $sst = $s->inspectionStatus(); @endphp
                <li><span class="pill {{ \App\Models\Equipment::statusPill($sst) }}">Scale {{ \App\Models\Equipment::statusLabel($sst) }}</span>
                    <a href="{{ route('scales.show', $s) }}">{{ $s->equip_id }} · {{ $s->name }}</a>
                    <span class="pb-muted">@if ($s->inspection_due_on)W&amp;M inspection {{ $sst === 'overdue' ? 'was due' : 'due' }} {{ Fmt::date($s->inspection_due_on) }}@else no inspection date in the scale register @endif</span></li>
            @endforeach
            @if ($review)
                <li><span class="pill blue">Setup</span><a href="{{ route('setup.index', ['products', 'review' => 1]) }}">{{ $review }} imported products need a quick review</a></li>
            @endif
            @if ($quarantine->isEmpty() && $deviations->isEmpty() && ! $review && $scaleAlerts->isEmpty())<li class="pb-muted">All clear.</li>@endif
        </ul>
    </section>
</div>
@endsection
