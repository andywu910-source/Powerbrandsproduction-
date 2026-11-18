@extends('layouts.app')
@section('title', 'Copy last week')
@section('page_title', 'Weekly Schedule')
@php use App\Support\Schedule; @endphp
@section('content')
@include('schedule._nav')
<section class="card" id="copyPlan">
    <div class="card-head"><h3>Copy last week → week of {{ $monday->format('M j, Y') }}</h3></div>
    @if (! $plan['prev'])
        <p>There is no earlier week to copy.</p>
    @else
        <p>From the <b>week of {{ $plan['prev']->week_start->format('M j, Y') }}</b>. This brings over the people, each person's day length (from their People settings) and their recurring days off. Jobs, notes and one-off days off are not copied.</p>
        <div class="table-wrap"><table class="pb-table">
            <thead><tr><th>Person</th><th>Day length</th><th>Recurring days off</th><th></th></tr></thead>
            <tbody>
            @foreach ($plan['rows'] as $row)
                <tr class="{{ $row['active'] ? '' : 'is-void' }}"><td><b>{{ $row['employee']->name }}</b>@if ($row['employee']->role) <span class="pb-muted">{{ $row['employee']->role }}</span>@endif</td>
                    <td>{{ Schedule::fmtHours($row['hours']) }} h</td><td>{{ $row['offs'] ?: '—' }}</td>
                    <td>{!! $row['active'] ? '<span class="pill ok">Copied</span>' : '<span class="pill bad">Not copied: inactive</span>' !!}</td></tr>
            @endforeach
            </tbody></table></div>
        @if ($plan['new']->count())
            <p class="pb-muted">Active but not on last week (add them on the week after copying): {{ $plan['new']->pluck('name')->implode(', ') }}.</p>
        @endif
        <form method="POST" action="{{ route('schedule.start') }}" class="pb-row" style="margin-top:14px">@csrf
            <input type="hidden" name="week" value="{{ $monday->toDateString() }}"><input type="hidden" name="copy" value="1">
            <button class="btn-save" id="copyConfirm">Copy to week of {{ $monday->format('M j') }}</button>
            <a class="pb-btn pb-btn-ghost" href="{{ route('schedule.index', ['week' => $monday->toDateString()]) }}">Cancel</a>
        </form>
    @endif
</section>
@endsection
