@extends('layouts.app')
@section('title', 'Schedule · People & setup')
@section('page_title', 'Weekly Schedule')
@php use App\Support\Schedule; @endphp
@section('content')
@include('schedule._nav')
<section class="card">
    <div class="card-head"><h3>People</h3><span class="pb-muted">Who can be scheduled. Day length and recurring days off are used when a week is set up or copied.</span></div>
    <div class="table-wrap"><table class="pb-table" id="schPeople">
        <thead><tr><th>Name</th><th>Role</th><th>Default day</th><th>Recurring off</th>@if ($showLabor)<th>Rate</th>@endif<th>Active</th></tr></thead>
        <tbody>@foreach ($people as $p)
            <tr class="{{ $p->active ? '' : 'is-void' }}"><td><b>{{ $p->name }}</b></td><td>{{ $p->role ?: '—' }}</td><td>{{ Schedule::fmtHours($p->default_hours) }} h</td>
                <td>{{ $p->recurringOffText() ?: '—' }}</td>@if ($showLabor)<td>{{ $p->hourly_rate !== null ? '$'.number_format($p->hourly_rate, 2) : 'default' }}</td>@endif<td>{{ $p->active ? 'Yes' : 'No' }}</td></tr>
        @endforeach</tbody></table></div>
    @if ($canEdit)
        @foreach ($people as $p)
            <details class="pb-details"><summary>Edit {{ $p->name }}</summary>@include('schedule._person-form', ['p' => $p])</details>
        @endforeach
        <details class="pb-details" open><summary>Add a person</summary>@include('schedule._person-form', ['p' => null])</details>
    @endif
</section>

<section class="card">
    <div class="card-head"><h3>Schedule setup</h3></div>
    <p><b>Who can edit:</b> {{ $editorsNow ? implode(', ', $editorsNow) : 'nobody yet' }}. Everyone else who signs in can look.</p>
    <p class="pb-muted">Labor dollars are {{ $showLabor ? 'shown' : 'hidden' }} (server setting PBP_SCHEDULE_SHOW_LABOR).</p>
    @if ($canEdit)
        <form method="POST" action="{{ route('schedule.setup') }}" class="pb-form pb-grid-3">@csrf
            <div class="f-field"><label for="sections">Week note sections (one per line)</label><textarea id="sections" class="pb-input" name="sections" rows="6">{{ implode("\n", $sections) }}</textarea></div>
            <div class="f-field"><label for="steps">Process steps offered on jobs (one per line)</label><textarea id="steps" class="pb-input" name="steps" rows="6">{{ implode("\n", $steps) }}</textarea></div>
            @if ($isAdmin)
                <div class="f-field"><label for="editors">Schedule editors (one per line)</label><textarea id="editors" class="pb-input" name="editors" rows="6">{{ str_replace(', ', "\n", $editorSpec) }}</textarea>
                    <small class="pb-muted">role:admin · role:supervisor · name:Jack (full or first name) · email:someone@powerbrandsny.com</small></div>
            @endif
            <div style="grid-column:1/-1"><button class="btn-save">Save setup</button></div>
        </form>
    @endif
</section>
@endsection
