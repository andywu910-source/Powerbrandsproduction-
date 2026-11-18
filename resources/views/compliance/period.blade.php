@extends('layouts.app')
@section('title', 'Compliance · '.\App\Models\ComplianceItem::FREQUENCIES[$freq])
@section('page_title', 'Compliance Checklists')
@php use App\Support\Compliance; $pct = $progress['total'] ? round(100 * $progress['done'] / $progress['total']) : 0; @endphp
@section('content')
@include('compliance._nav')
<div class="cmp-head">
    <span class="cmp-nav"><a href="{{ route('compliance.period', [$freq, 'period' => $prev->toDateString()]) }}" aria-label="Previous">‹</a></span>
    <h3 id="cmpPeriod">{{ Compliance::label($freq, $start) }}</h3>
    <span class="cmp-nav"><a href="{{ route('compliance.period', [$freq, 'period' => $next->toDateString()]) }}" aria-label="Next">›</a>
        @unless ($isCurrent)<a href="{{ route('compliance.period', $freq) }}">{{ ['weekly' => 'This week', 'monthly' => 'This month', 'quarterly' => 'This quarter', 'annual' => 'This year'][$freq] }}</a>@endunless</span>
    <div class="cmp-prog" id="cmpProgress"><b>{{ $progress['done'] }} of {{ $progress['total'] }} done</b><div class="bar"><i style="width:{{ $pct }}%"></i></div></div>
</div>
@unless ($canEdit)
    <div class="cmp-ro" id="cmpReadOnly">{{ $isCurrent ? 'Read-only.' : 'Past '.['weekly' => 'week', 'monthly' => 'month', 'quarterly' => 'quarter', 'annual' => 'year'][$freq].': read-only. Admins can still correct it.' }}</div>
@endunless
<div class="cmp-list">
@foreach ($items as $n => $it)
    @if ($it->standing && ! ($items[$n - 1]->standing ?? false))
        </div><h3 class="cmp-sec" id="cmpStanding">Standing logs <span class="pb-muted">kept all year, confirmed once a year</span></h3><div class="cmp-list">
    @endif
    @php $e = $entries[$it->id] ?? null; $done = (bool) $e?->done; @endphp
    <form method="post" action="{{ route('compliance.save', [$freq, $start->toDateString(), $it->id]) }}" class="cmp-item {{ $done ? 'done' : '' }}" id="item-{{ $it->id }}">
        @csrf
        <div class="top">
            <input type="hidden" name="done" value="0">
            <input type="checkbox" name="done" value="1" {{ $done ? 'checked' : '' }} @disabled(! $canEdit) aria-label="Done: {{ $it->title }}" @if ($canEdit) onchange="this.form.submit()" @endif>
            <div>
                <div class="ttl">{{ $n + 1 }}. {{ $it->title }}@if ($it->hidden) <span class="pb-muted">(hidden)</span>@endif</div>
                @if ($it->description)<div class="desc">{{ $it->description }}</div>@endif
                @if ($done)<div class="meta">✓ {{ $e->completedBy?->name ?? '—' }} · {{ $e->completed_on?->format('M j, Y') }}</div>@endif
            </div>
        </div>
        @if ($canEdit)
        <div class="cmp-fields">
            <label>Assignee<select name="assignee_user_id"><option value="">—</option>@foreach ($pickable as $u)<option value="{{ $u->id }}" @selected($e?->assignee_user_id === $u->id)>{{ $u->name }}</option>@endforeach</select></label>
            <label>Completed by<select name="completed_by_user_id"><option value="">{{ $done ? '—' : '(me, when checked)' }}</option>@foreach ($pickable as $u)<option value="{{ $u->id }}" @selected($e?->completed_by_user_id === $u->id)>{{ $u->name }}</option>@endforeach</select></label>
            <label>Date<input type="date" name="completed_on" value="{{ $e?->completed_on?->toDateString() }}"></label>
            <label>Note<textarea name="note" rows="1" maxlength="2000" placeholder="Optional">{{ $e?->note }}</textarea></label>
        </div>
        <div class="cmp-save"><button type="submit" class="pb-btn">Save</button></div>
        @elseif ($e && ($e->assignee || $e->note))
        <div class="desc" style="margin-top:6px">@if ($e->assignee)Assignee: {{ $e->assignee->name }}. @endif @if ($e->note)Note: {{ $e->note }}@endif</div>
        @endif
    </form>
@endforeach
</div>
@endsection
