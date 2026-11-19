@extends('layouts.app')
@section('title', 'Schedule')
@section('page_title', 'Weekly Schedule')
@php use App\Support\Schedule; use App\Models\ScheduleCell; $today = Schedule::today()->toDateString(); @endphp
@section('content')
@include('schedule._nav')
<section class="card">
    <div class="card-head sch-weekbar">
        <a class="pb-btn pb-btn-ghost sch-navbtn" href="{{ route('schedule.index', ['week' => $monday->subWeek()->toDateString()]) }}" aria-label="Previous week">‹</a>
        <h3>Week of {{ $monday->format('M j, Y') }} <span class="pb-muted">Mon {{ $monday->format('n/j') }} – Fri {{ $monday->addDays(4)->format('n/j') }}</span></h3>
        <a class="pb-btn pb-btn-ghost sch-navbtn" href="{{ route('schedule.index', ['week' => $monday->addWeek()->toDateString()]) }}" aria-label="Next week">›</a>
        <a class="pb-btn pb-btn-ghost" href="{{ route('schedule.index') }}">This week</a>
        <form method="GET" class="sch-jump"><label class="sr-only" for="wk">Go to week</label><input id="wk" type="date" name="week" class="pb-input" value="{{ $monday->toDateString() }}" onchange="this.form.submit()"></form>
    </div>

    @if (! $week)
        <div class="sch-empty" id="weekEmpty">
            <p><b>This week isn't set up yet.</b></p>
            @if ($canEdit)
                @if ($prev)
                    <a class="btn-save" id="copyLastWeek" href="{{ route('schedule.copy', ['week' => $monday->toDateString()]) }}">Copy last week</a>
                    <span class="pb-muted">from the {{ strtolower($prev->label()) }}: the people, their day length and recurring days off (jobs are not copied).</span>
                @endif
                <form method="POST" action="{{ route('schedule.start') }}" style="display:inline">@csrf
                    <input type="hidden" name="week" value="{{ $monday->toDateString() }}"><input type="hidden" name="copy" value="0">
                    <button class="pb-btn pb-btn-ghost">Start from the people list</button></form>
            @else
                <p class="pb-muted">A schedule editor sets the week up.</p>
            @endif
        </div>
    @else
        <p class="sch-legend" id="schLegend"><b>Blank = working.</b> Everyone counts as working their day length unless a day is marked
            <span class="sch-chip off">Off</span> or <span class="sch-chip partial">Partial</span>.@if ($canEdit) Click a day to change it, or use All working / All off for a person or a day.@endif</p>
        <div class="table-wrap"><table class="pb-table sch-grid" id="schGrid">
            <thead><tr><th class="sch-person">Person</th>
                @foreach ($days as $d)<th class="{{ $d->toDateString() === $today ? 'is-today' : '' }}">{{ $d->format('D') }} <span class="pb-muted">{{ $d->format('n/j') }}</span>
                    @if ($canEdit)<div class="sch-bulk sch-bulk-day">
                        <form method="POST" action="{{ route('schedule.bulk.day', [$week, $d->toDateString()]) }}">@csrf<input type="hidden" name="status" value="working"><button class="sch-bulk-btn" title="Everyone working on {{ $d->format('l') }}" aria-label="Everyone working on {{ $d->format('l n/j') }}">All working</button></form>
                        <form method="POST" action="{{ route('schedule.bulk.day', [$week, $d->toDateString()]) }}" onsubmit="return confirm('Mark everyone off on {{ $d->format('l n/j') }}?')">@csrf<input type="hidden" name="status" value="off"><button class="sch-bulk-btn off" title="Everyone off on {{ $d->format('l') }} (e.g. a holiday)" aria-label="Everyone off on {{ $d->format('l n/j') }}">All off</button></form>
                    </div>@endif
                </th>@endforeach
                @if ($showLabor)<th class="sch-hours">Labor $</th>@endif
                <th class="sch-total" title="Scheduled hours for this week (Working = the person's day length, Partial = its hours, Off = 0). There is no clock-in data, so these are not hours clocked.">Total hours <span class="sch-total-note">scheduled</span></th></tr></thead>
            <tbody>
            @foreach ($grid['rows'] as $row)
                @php $e = $row['employee']; $r = $row['roster']; @endphp
                <tr>
                    <th class="sch-person" scope="row"><b>{{ $e->name }}</b>
                        @if ($canEdit)<div class="sch-bulk sch-bulk-row">
                            <form method="POST" action="{{ route('schedule.bulk.row', [$week, $e]) }}">@csrf<input type="hidden" name="status" value="working"><button class="sch-bulk-btn" aria-label="{{ $e->name }}: all week working">All working</button></form>
                            <form method="POST" action="{{ route('schedule.bulk.row', [$week, $e]) }}" onsubmit="return confirm('Mark {{ e($e->name) }} off all week?')">@csrf<input type="hidden" name="status" value="off"><button class="sch-bulk-btn off" aria-label="{{ $e->name }}: all week off">All off</button></form>
                        </div>@endif
                    </th>
                    @foreach ($days as $d)
                        @php $c = $row['cells']->get($d->toDateString()); $st = ScheduleCell::effective($c); $blank = ScheduleCell::isDefault($c); @endphp
                        <td class="sch-cell st-{{ $st }} {{ $blank ? 'is-default' : '' }} {{ $d->toDateString() === $today ? 'is-today' : '' }}">
                            <a class="sch-cell-link" href="{{ route('schedule.cell', [$week, $e, $d->toDateString()]) }}" aria-label="{{ $e->name }}, {{ $d->format('l') }}: {{ ScheduleCell::STATUSES[$st] }}{{ $blank ? ' (blank = working)' : '' }}">
                                @if ($st === 'off')<span class="sch-chip off">Off</span>
                                @elseif ($st === 'partial')<span class="sch-chip partial">Partial · {{ Schedule::fmtHours($c->hours) }} h</span>
                                @elseif ($blank)<span class="sch-chip working is-default" title="Blank = working">Working</span>
                                @else<span class="sch-chip working">Working</span>@endif
                                @if ($c?->note)<span class="sch-note">{{ $c->note }}</span>@endif
                                @foreach ($c?->jobs ?? [] as $job) @include('schedule._job', ['job' => $job]) @endforeach
                            </a>
                        </td>
                    @endforeach
                    @if ($showLabor)<td class="sch-hours">${{ number_format($row['labor'], 0) }}</td>@endif
                    <td class="sch-total" title="{{ $e->name }}: {{ Schedule::fmtHours($row['hours']) }} scheduled hours this week">{{ Schedule::fmtHours($row['hours']) }} h</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @if ($canEdit)
            <details class="pb-details sch-roster-edit"><summary>People on this week</summary>
                <div class="sch-roster-list">
                @foreach ($grid['rows'] as $row)
                    <form method="POST" action="{{ route('schedule.roster.save', $row['roster']) }}" class="sch-roster-row">@csrf
                        <span>{{ $row['employee']->name }}</span>
                        <label>Day length <input class="pb-input" type="number" step="0.25" min="0" max="16" name="daily_hours" value="{{ Schedule::fmtHours($row['roster']->daily_hours) }}"></label>
                        <button class="pb-btn pb-btn-ghost">Save</button>
                        <button class="pb-btn pb-btn-ghost pb-danger" name="remove" value="1" onclick="return confirm('Take {{ e($row['employee']->name) }} off this week?')">Take off this week</button>
                    </form>
                @endforeach
                </div>
                @if ($addable->count())
                    <form method="POST" action="{{ route('schedule.roster.add', $week) }}" class="pb-inline-form">@csrf
                        <div class="f-field"><label>Add someone to this week</label><select class="pb-select" name="employee_id">@foreach ($addable as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                        <button class="pb-btn pb-btn-ghost">Add</button></form>
                @endif
            </details>
        @endif
    @endif
</section>

@if ($week)
@php
    $going = $notes->get(\App\Models\ScheduleNote::GOING_TO_LAB, collect())->filter(fn ($n) => $n->batch);
    $pending = $notes->get(\App\Models\ScheduleNote::PENDING, collect());
    $goingIds = $going->pluck('batch_id')->all();
@endphp
<section class="card" id="lab">
    <div class="card-head"><h3>Going to lab</h3><span class="pb-muted">Incoming jobs planned for the lab this week. Adding one does not change its status; tick it when it is at the lab and it becomes In lab.</span></div>
    <div class="table-wrap"><table class="pb-table sch-lab" id="goingToLab">
        <thead><tr><th>At lab</th><th>Batch number</th><th>Product</th><th>Brand</th><th>Units planned</th><th>Status</th>@if ($canEdit)<th></th>@endif</tr></thead>
        <tbody>
        @forelse ($going as $n) @include('schedule._batchrow', ['b' => $n->batch, 'labNote' => $n, 'removeNote' => $canEdit ? $n : null])
        @empty <tr><td colspan="7" class="pb-muted">Nothing picked yet.</td></tr>
        @endforelse
        </tbody></table></div>
    @if ($canEdit)
        <form method="POST" action="{{ route('schedule.note.add', $week) }}" class="sch-note-add" id="addLab">@csrf<input type="hidden" name="section" value="{{ \App\Models\ScheduleNote::GOING_TO_LAB }}">
            <select class="pb-select" name="batch" aria-label="Add an Incoming job to Going to lab" required>
                <option value="">Add an Incoming job…</option>
                @foreach ($incomingBatches->reject(fn ($b) => in_array($b->id, $goingIds)) as $b)<option value="{{ $b->batch_number }}">{{ \App\Http\Controllers\ScheduleController::batchOption($b) }}</option>@endforeach
            </select><button class="pb-btn pb-btn-ghost">Add</button></form>
        @error('lab_batch')<p class="pb-error">{{ $message }}</p>@enderror
    @endif
</section>

<section class="card" id="inLab">
    <div class="card-head"><h3>In lab</h3><span class="pb-muted">Every batch marked In lab in the Batch Log (updates by itself).</span></div>
    <div class="table-wrap"><table class="pb-table sch-lab" id="inLabTable">
        <thead><tr><th>Batch number</th><th>Product</th><th>Brand</th><th>Units planned</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($inLab as $b) @include('schedule._batchrow', ['b' => $b])
        @empty <tr><td colspan="5" class="pb-muted">No batches are In lab.</td></tr>
        @endforelse
        </tbody></table></div>
</section>

<section class="card" id="pending">
    <div class="card-head"><h3>Pending</h3><span class="pb-muted">Notes attached to a batch, e.g. "[batch #] - waiting for apollo labels".</span></div>
    <ul class="sch-pending">
    @forelse ($pending as $n)
        <li>
            @if ($canEdit)
                <form method="POST" action="{{ route('schedule.note.save', $n) }}" class="sch-pending-row">@csrf
                    @if ($n->batch)<a class="pb-bn" href="{{ route('batch-log.show', $n->batch) }}">{{ $n->batch->batch_number }}</a> <span class="{{ $n->batch->logStatusClass() }}">{{ $n->batch->logStatusLabel() }}</span> -@endif
                    <input class="pb-input" name="text" maxlength="255" value="{{ $n->text }}" aria-label="Note for {{ $n->batch?->batch_number ?? 'pending item' }}" required>
                    <button class="pb-btn pb-btn-ghost">Save</button></form>
                <form method="POST" action="{{ route('schedule.note.save', $n) }}">@csrf<input type="hidden" name="remove" value="1"><button class="sch-x" aria-label="Remove {{ $n->line() }}">×</button></form>
            @else
                <span>{{ $n->line() }}</span>
            @endif
        </li>
    @empty
        <li class="pb-muted">Nothing pending.</li>
    @endforelse
    </ul>
    @if ($canEdit)
        <form method="POST" action="{{ route('schedule.note.add', $week) }}" class="sch-note-add sch-pending-add" id="addPending">@csrf<input type="hidden" name="section" value="{{ \App\Models\ScheduleNote::PENDING }}">
            <input class="pb-input pb-bn" name="batch" list="openBatchList" placeholder="Batch # or product" aria-label="Batch for the note" autocomplete="off" value="{{ old('section') === 'Pending' ? old('batch') : '' }}">
            <input class="pb-input" name="text" maxlength="255" placeholder="Note, e.g. waiting for apollo labels" aria-label="Pending note" required>
            <button class="pb-btn pb-btn-ghost">Add</button></form>
        @error('pending_batch')<p class="pb-error">{{ $message }}</p>@enderror
        @error('pending_text')<p class="pb-error">{{ $message }}</p>@enderror
        <datalist id="openBatchList">@foreach ($openBatches as $b)<option value="{{ \App\Http\Controllers\ScheduleController::batchOption($b) }}">@endforeach</datalist>
    @endif
</section>
@endif
@endsection
