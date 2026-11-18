@extends('layouts.app')
@section('title', $t->exists ? $t->name : 'New checklist')
@section('page_title', $t->exists ? $t->name : 'New checklist')
@section('parent_title', 'Checklists')
@section('parent_url', route('checklists.index'))
@php use App\Models\ChecklistTemplate; use App\Models\ChecklistTemplateItem; use App\Support\Fmt; @endphp
@section('content')
<form method="POST" action="{{ $t->exists ? route('checklists.templates.update', $t) : route('checklists.templates.store') }}" class="pb-form">@csrf
    @if ($t->exists) @method('PUT') @endif
<section class="card">
    <div class="card-head"><h3>Checklist</h3>
        @if ($t->exists)<span class="pill blue">Version {{ $v?->version_no }}</span>@endif
        <span class="pb-muted">Changes to the items or sign-off make a new version. Checklists already filled in keep their old version.</span></div>
    <div class="pb-grid-3">
        <div class="f-field"><label>Name</label><input class="pb-input" name="name" value="{{ old('name', $t->name) }}" required></div>
        @unless ($t->exists)
            <div class="f-field"><label>Short code (letters, no spaces)</label><input class="pb-input" name="code" value="{{ old('code', $t->code) }}" placeholder="e.g. daily_restroom" required></div>
        @endunless
        <div class="f-field"><label>Type</label><select name="category" class="pb-select">@foreach (ChecklistTemplate::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', $t->category) === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="f-field"><label>Reference (SOP / rule)</label><input class="pb-input" name="spec_ref" value="{{ old('spec_ref', $t->spec_ref) }}"></div>
        <div class="f-field"><label>How often</label><select name="frequency" class="pb-select">@foreach (ChecklistTemplate::FREQUENCIES as $k => $l)<option value="{{ $k }}" @selected(old('frequency', $t->frequency) === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="f-field"><label>Weekly: day</label><select name="weekday" class="pb-select"><option value="">Monday</option>@foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $k => $l)<option value="{{ $k }}" @selected((int) old('weekday', $t->weekday) === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="f-field"><label>Monthly: day of month</label><input class="pb-input" type="number" min="1" max="31" name="day_of_month" value="{{ old('day_of_month', $t->day_of_month) }}" placeholder="1"></div>
        <div class="f-field"><label>Due at (time of day)</label><input class="pb-input" type="time" name="due_time" value="{{ old('due_time', substr((string) $t->due_time, 0, 5)) }}" required></div>
        <div class="f-field"><label>Late after (hours past due)</label><input class="pb-input" type="number" min="0" max="720" name="grace_hours" value="{{ old('grace_hours', $t->grace_hours) }}" required></div>
        <div class="f-field"><label>One per</label><select name="scope_type" class="pb-select">@foreach (['none' => 'Whole site', 'room' => 'Each room', 'equipment' => 'Each piece of equipment', 'scale' => 'Each active scale (scale register)'] as $k => $l)<option value="{{ $k }}" @selected(old('scope_type', $t->scope_type) === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="f-field"><label>Filled in by</label><select name="completed_by_role" class="pb-select">@foreach (ChecklistTemplate::ROLES as $k => $l)<option value="{{ $k }}" @selected(old('completed_by_role', $v?->completed_by_role) === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="f-field"><label>Second-person check by</label><select name="verifier_role" class="pb-select"><option value="">Not needed</option>@foreach (ChecklistTemplate::ROLES as $k => $l)<option value="{{ $k }}" @selected(old('verifier_role', $v?->verifier_role) === $k)>{{ $l }}</option>@endforeach</select></div>
        @if ($t->exists)
            <label class="pb-check-inline" style="margin-top:26px"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', $t->active))> Active (new checklists are created)</label>
        @endif
    </div>
</section>

<section class="card">
    <div class="card-head"><h3>Items</h3><span class="pb-muted">Leave a row's question empty to remove it. Blank rows at the bottom add items.</span></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>#</th><th>Question / check</th><th>Answer type</th><th>Min</th><th>Max</th><th>Unit</th><th>If it fails</th></tr></thead>
        <tbody>
        @foreach ($items->concat(collect(range(1, 3))->map(fn () => new ChecklistTemplateItem(['response_type' => 'pass_fail', 'fail_requires' => 'note']))) as $k => $it)
            <tr>
                <td>{{ $it->exists ? $it->item_no : '+' }}</td>
                <td><input class="pb-input" name="items[{{ $k }}][prompt]" value="{{ old("items.$k.prompt", $it->prompt) }}" style="min-width:320px"></td>
                <td><select name="items[{{ $k }}][response_type]" class="pb-select">@foreach (ChecklistTemplateItem::TYPES as $tk => $tl)<option value="{{ $tk }}" @selected(old("items.$k.response_type", $it->response_type) === $tk)>{{ $tl }}</option>@endforeach</select></td>
                <td><input class="pb-input" type="number" step="any" name="items[{{ $k }}][min]" value="{{ old("items.$k.min", $it->min) }}" style="width:90px"></td>
                <td><input class="pb-input" type="number" step="any" name="items[{{ $k }}][max]" value="{{ old("items.$k.max", $it->max) }}" style="width:90px"></td>
                <td><input class="pb-input" name="items[{{ $k }}][unit]" value="{{ old("items.$k.unit", $it->unit) }}" style="width:80px"></td>
                <td><select name="items[{{ $k }}][fail_requires]" class="pb-select"><option value="note" @selected(old("items.$k.fail_requires", $it->fail_requires) === 'note')>Note what was done</option><option value="deviation" @selected(old("items.$k.fail_requires", $it->fail_requires) === 'deviation')>Open a deviation</option></select></td>
            </tr>
        @endforeach
        </tbody></table></div>
    <div class="f-field" style="margin-top:12px"><label>What changed and why (kept with the version)</label><input class="pb-input" name="change_note" value="{{ old('change_note') }}"></div>
    <div style="margin-top:12px"><button class="btn-save" type="submit">{{ $t->exists ? 'Save' : 'Create checklist' }}</button></div>
</section>
</form>

@if ($t->exists)
<section class="card">
    <div class="card-head"><h3>Version history</h3></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>Version</th><th>Made</th><th>By</th><th>Items</th><th>Filled in by / checked by</th><th>Note</th></tr></thead>
        <tbody>
        @foreach ($t->versions->sortByDesc('version_no') as $ver)
            <tr><td>v{{ $ver->version_no }} @if ($ver->id === $t->current_version_id)<span class="pill ok">Current</span>@endif</td>
                <td>{{ Fmt::dt($ver->created_at) }}</td><td>{{ \App\Models\User::find($ver->created_by)?->name ?? 'Starter data' }}</td>
                <td>{{ $ver->items()->count() }}</td>
                <td>{{ ChecklistTemplate::ROLES[$ver->completed_by_role] ?? $ver->completed_by_role }} / {{ $ver->verifier_role ? (ChecklistTemplate::ROLES[$ver->verifier_role] ?? $ver->verifier_role) : 'no check' }}</td>
                <td>{{ $ver->change_note }}</td></tr>
        @endforeach
        </tbody></table></div>
</section>
@endif
@endsection
