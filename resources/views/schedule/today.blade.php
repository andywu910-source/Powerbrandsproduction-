@extends('layouts.app')
@section('title', 'Today · Schedule')
@section('page_title', 'Today')
@php use App\Support\Schedule; $monday = Schedule::monday($day->toDateString()); @endphp
@section('content')
@include('schedule._nav')
<div class="sch-today" id="schToday">
    <div class="sch-today-bar">
        <a class="pb-btn pb-btn-ghost sch-navbtn" href="{{ route('schedule.today', ['day' => $day->subWeekday()->toDateString()]) }}" aria-label="Previous day">‹</a>
        <h3>{{ $day->format('l, M j') }}</h3>
        <a class="pb-btn pb-btn-ghost sch-navbtn" href="{{ route('schedule.today', ['day' => $day->addWeekday()->toDateString()]) }}" aria-label="Next day">›</a>
    </div>
    @if (! $day->isWeekday())
        <p class="pb-muted">The schedule covers Monday to Friday.</p>
    @elseif (! $week)
        <p class="pb-muted">This week isn't set up yet.</p>
    @else
        @php
            $working = collect($rows)->filter(fn ($r) => in_array($r['cell']?->status, ['working', 'partial'], true));
            $off = collect($rows)->filter(fn ($r) => $r['cell']?->status === 'off');
            $none = collect($rows)->filter(fn ($r) => ! $r['cell']?->status);
        @endphp
        <p class="pb-muted">{{ $working->count() }} working · {{ Schedule::fmtHours($working->sum(fn ($r) => \App\Models\ScheduleCell::hoursFor($r['cell'], (float) $r['roster']->daily_hours))) }} h planned</p>
        @foreach ($working as $r)
            <a class="sch-today-card" href="{{ route('schedule.cell', [$week, $r['employee'], $day->toDateString()]) }}">
                <div class="sch-today-head"><b>{{ $r['employee']->name }}</b>
                    <span class="sch-chip {{ $r['cell']->status }}">{{ $r['cell']->status === 'partial' ? 'Partial · '.Schedule::fmtHours($r['cell']->hours).' h' : Schedule::fmtHours($r['roster']->daily_hours).' h' }}</span></div>
                @if ($r['cell']->note)<div class="sch-note">{{ $r['cell']->note }}</div>@endif
                @foreach ($r['cell']->jobs as $job) @include('schedule._job', ['job' => $job]) @endforeach
            </a>
        @endforeach
        @if ($off->count())<p class="sch-today-off"><b>Off:</b> {{ $off->map(fn ($r) => $r['employee']->name)->implode(', ') }}</p>@endif
        @if ($none->count())<p class="pb-muted"><b>Not scheduled:</b> {{ $none->map(fn ($r) => $r['employee']->name)->implode(', ') }}</p>@endif
        @php $pend = $notes->get(\App\Models\ScheduleNote::PENDING, collect()); @endphp
        @if ($pend->count())
            <div class="sch-note-sec"><h4>Pending</h4><ul class="sch-check">
                @foreach ($pend as $n)<li><span class="sch-check-text">{{ $n->line() }}</span></li>@endforeach
            </ul></div>
        @endif
    @endif
</div>
@endsection
