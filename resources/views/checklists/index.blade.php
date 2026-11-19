@extends('layouts.app')
@section('title', 'Checklists')
@section('page_title', 'Checklists')
@php
    use App\Support\Fmt;
    use App\Models\ChecklistTemplate;
    $canManage = auth()->user()->isSupervisorOrAdmin();
    $ro = auth()->user()->isViewer();
    $scopeLabel = function (?string $s) {
        if (! $s) return '';
        [$type, $id] = explode(':', $s) + [null, null];
        return match ($type) {
            'room' => 'Room '.(\App\Models\Room::find($id)?->room_no ?? $id),
            'equipment' => ($e = \App\Models\Equipment::find($id)) ? $e->equip_id.' · '.$e->name : $id,
            default => $s,
        };
    };
@endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>To do</h3>
        <span class="pill bad">{{ $open->filter(fn ($i) => $i->displayStatus() === 'overdue')->count() }} overdue</span>
        <span class="pill warn">{{ $open->filter(fn ($i) => in_array($i->displayStatus(), ['due', 'in_progress'], true))->count() }} due</span>
        <span class="pill blue">{{ $open->where('status', 'awaiting_verification')->count() }} waiting for a verifier</span>
    </div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>Checklist</th><th>For</th><th>How often</th><th>Due</th><th>Late after</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($open as $i)
            @php $st = $i->displayStatus(); @endphp
            <tr>
                <td><a href="{{ route('checklists.show', $i) }}"><b>{{ $i->template->name }}</b></a><br><span class="pb-muted">{{ $i->public_id }}</span></td>
                <td>{{ $i->batch ? 'Batch '.$i->batch->batch_number : $scopeLabel($i->scope_key) }}</td>
                <td>{{ ChecklistTemplate::FREQUENCIES[$i->template->frequency] ?? '' }}</td>
                <td>{{ Fmt::dt($i->due_at) }}</td>
                <td>{{ Fmt::dt($i->overdue_at) ?: '—' }}</td>
                <td><span class="pill {{ Fmt::pill($st) }}">{{ \App\Models\ChecklistInstance::STATUSES[$st] ?? $st }}</span></td>
                <td>@unless ($ro)<a class="pb-btn pb-btn-ghost" href="{{ route('checklists.show', $i) }}">{{ $i->status === 'awaiting_verification' ? 'Verify' : 'Fill in' }}</a>@endunless</td>
            </tr>
        @empty
            <tr><td colspan="7" class="pb-muted">Nothing due right now.</td></tr>
        @endforelse
        </tbody></table></div>
</section>

@if ($onDemand->isNotEmpty() && ! $ro)
<section class="card">
    <div class="card-head"><h3>Start a checklist now</h3><span class="pb-muted">For checklists that are done when needed, not on a schedule.</span></div>
    <div class="pb-row">
        @foreach ($onDemand as $t)
            <form method="POST" action="{{ route('checklists.start', $t) }}">@csrf<button class="pb-btn pb-btn-ghost" type="submit">{{ $t->name }}</button></form>
        @endforeach
    </div>
</section>
@endif

<section class="card">
    <div class="card-head"><h3>Recently completed</h3></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>Checklist</th><th>Period</th><th>Completed</th><th></th></tr></thead>
        <tbody>
        @forelse ($done as $i)
            <tr><td><a href="{{ route('checklists.show', $i) }}">{{ $i->template->name }}</a><br><span class="pb-muted">{{ $i->public_id }}</span></td>
                <td>{{ str_starts_with((string) $i->period_key, 'run-') ? 'When needed' : $i->period_key }}</td><td>{{ Fmt::dt($i->completed_at) }}</td>
                <td><span class="pill ok">Completed</span></td></tr>
        @empty
            <tr><td colspan="4" class="pb-muted">None yet.</td></tr>
        @endforelse
        </tbody></table></div>
</section>

<section class="card">
    <div class="card-head"><h3>Checklist templates</h3><span class="pb-muted">Changing a template makes a new version for future checklists; filled-in ones keep their version.</span>
        <span class="pb-spacer"></span>
        @if ($canManage)<a class="btn-save" href="{{ route('checklists.templates.create') }}">New checklist</a>@endif</div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>Name</th><th>Type</th><th>How often</th><th>Due</th><th>Version</th><th>Items</th><th>Active</th></tr></thead>
        <tbody>
        @foreach ($templates as $t)
            <tr class="{{ $t->active ? '' : 'is-void' }}">
                <td>@if ($canManage)<a href="{{ route('checklists.templates.edit', $t) }}"><b>{{ $t->name }}</b></a>@else<b>{{ $t->name }}</b>@endif
                    @if ($t->spec_ref)<br><span class="pb-muted">Ref {{ $t->spec_ref }}</span>@endif</td>
                <td>{{ ChecklistTemplate::CATEGORIES[$t->category] ?? $t->category }}</td>
                <td>{{ ChecklistTemplate::FREQUENCIES[$t->frequency] ?? $t->frequency }}
                    @if ($t->frequency === 'weekly' && $t->weekday) · {{ ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$t->weekday] }}@endif
                    @if ($t->frequency === 'monthly' && $t->day_of_month) · day {{ $t->day_of_month }}@endif</td>
                <td>{{ $t->frequency === 'on_demand' ? '—' : $t->due_time.' (+'.$t->grace_hours.' h)' }}</td>
                <td>v{{ $t->currentVersion?->version_no }}</td>
                <td>{{ $t->currentVersion?->items()->count() }}</td>
                <td>{!! $t->active ? '<span class="pill ok">Yes</span>' : '<span class="pill">No</span>' !!}
                    @if ($rep = \App\Support\Retirements::replacedBy($t))<br><span class="pb-muted">@if (\App\Support\Retirements::replacementActive($rep))Retired: use <a href="{{ route('logs.index', ['q' => $rep]) }}">Compliance {{ $rep }}</a>@else Retired (no longer kept)@endif</span>@endif</td>
            </tr>
        @endforeach
        </tbody></table></div>
</section>
@endsection
