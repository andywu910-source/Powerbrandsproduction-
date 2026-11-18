@extends('layouts.app')
@section('title', 'Schedule · '.$employee->name)
@section('page_title', 'Weekly Schedule')
@php use App\Support\Schedule; use App\Models\ScheduleCell; $monday = $week->week_start; $st = $cell->status; @endphp
@section('content')
@include('schedule._nav')
<section class="card">
    <div class="card-head"><h3>{{ $employee->name }} · {{ $day->format('l, M j') }}</h3>
        <a class="pb-btn pb-btn-ghost" href="{{ route('schedule.index', ['week' => $monday->toDateString()]) }}">← Back to the week</a></div>
    <p class="pb-muted">Day length this week: {{ Schedule::fmtHours($roster->daily_hours) }} h.@if ($cell->from_recurring) This day was filled in from a recurring day off.@endif</p>
    @if ($canEdit)
        <form method="POST" action="{{ route('schedule.cell.save', [$week, $employee, $day->toDateString()]) }}" class="pb-form" id="cellForm">@csrf
            <fieldset class="sch-status-pick"><legend>Day</legend>
                @foreach (['working' => 'Working ('.Schedule::fmtHours($roster->daily_hours).' h)', 'partial' => 'Partial day', 'off' => 'Off', '' => 'Not scheduled'] as $k => $l)
                    <label class="sch-opt"><input type="radio" name="status" value="{{ $k }}" @checked(old('status', $st ?? '') === $k)> <span>{{ $l }}</span></label>
                @endforeach
            </fieldset>
            <div class="pb-grid-3">
                <div class="f-field" id="hoursField"><label for="hours">Hours (partial day)</label><input id="hours" class="pb-input" type="number" step="0.25" min="0.25" max="16" name="hours" value="{{ old('hours', $cell->hours !== null ? Schedule::fmtHours($cell->hours) : '') }}"></div>
                <div class="f-field" style="grid-column:span 2"><label for="note">Note</label><input id="note" class="pb-input" name="note" maxlength="500" value="{{ old('note', $cell->note) }}" placeholder="e.g. left at 4, came at 10:30"></div>
            </div>
            <div><button class="btn-save">Save day</button></div>
        </form>
        <script>
        (function () { var f = document.getElementById('cellForm'), h = document.getElementById('hoursField');
            function upd() { var v = (f.querySelector('input[name=status]:checked') || {}).value; h.style.display = v === 'partial' ? '' : 'none'; document.getElementById('hours').required = v === 'partial'; }
            f.addEventListener('change', upd); upd(); })();
        </script>
    @else
        <p><b>{{ $st ? ScheduleCell::STATUSES[$st] : 'Not scheduled' }}</b>{{ $st === 'partial' ? ' · '.Schedule::fmtHours($cell->hours).' h' : '' }}{{ $cell->note ? ' · '.$cell->note : '' }}</p>
    @endif
</section>

<section class="card" id="jobs">
    <div class="card-head"><h3>Jobs</h3></div>
    @forelse ($jobs as $job)
        <div class="sch-job-card" id="job-{{ $job->id }}">
            <div class="sch-job-head"><b>{{ $job->title() }}</b>
                @if ($job->batch)
                    <span class="sch-batch">Batch <a class="pb-bn" href="{{ route('batch-log.show', $job->batch) }}">{{ $job->batch->batch_number }}</a>
                        <span class="{{ $job->batch->logStatusClass() }}">{{ $job->batch->logStatusLabel() }}</span></span>
                @elseif ($job->product_id && ! auth()->user()->isViewer())
                    <a class="btn-save sch-start" href="{{ route('production.index', ['product_id' => $job->product_id, 'schedule_job' => $job->id]) }}">Start job</a>
                @endif
            </div>
            @if ($canEdit)
                <form method="POST" action="{{ route('schedule.job.save', $job) }}" class="pb-grid-3 sch-job-edit">@csrf
                    <div class="f-field"><label>Process step</label><input class="pb-input" name="process_step" list="schSteps" maxlength="60" value="{{ $job->process_step }}"></div>
                    <div class="f-field"><label>Batch number (link an existing batch)</label><input class="pb-input pb-bn" name="batch_number" maxlength="60" value="{{ $job->batch?->batch_number }}" placeholder="Filled in by Start job"></div>
                    <div class="f-field" style="grid-column:1/-1"><label>Note</label><input class="pb-input" name="job_note" maxlength="255" value="{{ $job->note }}"></div>
                    <div class="pb-row" style="grid-column:1/-1"><button class="pb-btn pb-btn-ghost">Save job</button></div>
                </form>
                <form method="POST" action="{{ route('schedule.job.remove', $job) }}" onsubmit="return confirm('Remove this job from the schedule?')">@csrf<button class="pb-btn pb-btn-ghost pb-danger">Remove job</button></form>
            @else
                <p class="pb-muted">{{ $job->process_step }}{{ $job->note ? ' · '.$job->note : '' }}</p>
            @endif
        </div>
    @empty
        <p class="pb-muted">No jobs yet.</p>
    @endforelse

    @if ($canEdit)
        <form method="POST" action="{{ route('schedule.job.add', [$week, $employee, $day->toDateString()]) }}" class="pb-form sch-add-job" id="addJob">@csrf
            <h4>Add a job</h4>
            <div class="f-field"><label for="jobBatch">Batch # or product</label>
                <input id="jobBatch" class="pb-input pb-bn" name="batch" list="openBatchList" required autocomplete="off" spellcheck="false" value="{{ old('batch') }}" placeholder="Start typing a batch number or product name">
                <span class="pb-muted">Only batches that are not Finished.</span>
                @error('batch')<p class="pb-error">{{ $message }}</p>@enderror</div>
            <datalist id="openBatchList">@foreach ($openBatches as $b)<option value="{{ \App\Http\Controllers\ScheduleController::batchOption($b) }}">@endforeach</datalist>
            <div class="pb-grid-3">
                <div class="f-field"><label for="process_step">Process step (optional)</label><input id="process_step" class="pb-input" name="process_step" list="schSteps" maxlength="60" placeholder="grind, fill, label, pack, lab…"></div>
                <div class="f-field"><label for="job_note">Note (optional)</label><input id="job_note" class="pb-input" name="job_note" maxlength="255"></div>
            </div>
            <div><button class="btn-save">Add job</button></div>
        </form>
        <datalist id="schSteps">@foreach ($steps as $s)<option value="{{ $s }}">@endforeach</datalist>
    @endif
</section>
@endsection
