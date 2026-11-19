@extends('layouts.app')
@section('title', 'Compliance · '.\App\Models\ComplianceItem::FREQUENCIES[$freq])
@section('page_title', 'Compliance Checklists')
@php use App\Support\Compliance; $me = auth()->user(); $selfSign = $me->signsAsSelf(); $signMode = \App\Models\Setting::get('signing_auth_mode', 'name'); $pct = $progress['total'] ? round(100 * $progress['done'] / $progress['total']) : 0; @endphp
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
    @php $e = $entries[$it->id] ?? null; $done = (bool) $e?->done; $sig = $e?->validSignature(); $allSigs = $e ? $e->signatures : collect(); $revs = $e ? $e->revisions : collect(); $pending = $e?->pendingRevision(); $canRevise = $canEdit && $sig && ($me->isAdmin() || $sig->user_id === $me->id || $sig->recorded_by_user_id === $me->id); @endphp
    <form method="post" action="{{ route('compliance.save', [$freq, $start->toDateString(), $it->id]) }}" class="cmp-item {{ $done ? 'done' : '' }}" id="item-{{ $it->id }}">
        @csrf
        <div class="top">
            <input type="hidden" name="done" value="0">
            <input type="checkbox" name="done" value="1" {{ $done ? 'checked' : '' }} @disabled(! $canEdit || $sig) aria-label="Done: {{ $it->title }}" @if ($canEdit && ! $sig && $signMode === 'name') onchange="if (this.checked && ! confirm('Check this off? It signs it off in {{ $selfSign ? 'your name' : 'the name picked' }} with today\'s date and time. You can revise it later; the original sign-off stays on record.')) { this.checked = false; return; } this.form.submit()" @endif>
            <div>
                <div class="ttl">{{ $n + 1 }}. {{ $it->title }}@if ($it->hidden) <span class="pb-muted">(hidden)</span>@endif</div>
                @if ($it->description)<div class="desc">{{ $it->description }}</div>@endif
                @if ($done)<div class="meta">✓ {{ $e->completedBy?->name ?? '—' }} · {{ $e->completed_on?->format('M j, Y') }}@if ($pending) · <b style="color:#b45309" id="pending-{{ $it->id }}">revised by {{ $pending->user_name }}, waiting for re-confirmation</b>@elseif ($revs->where('kind', 'confirmed')->isNotEmpty()) · <span class="pb-muted">revised and re-confirmed</span>@endif</div>@endif
                @if ($canEdit && ! $done)<div class="desc" id="signHint-{{ $it->id }}">Checking this off signs it in {{ $selfSign ? 'your name' : 'the name picked' }} (date and time recorded). You can revise it later; the original sign-off stays on record.</div>@endif
            </div>
        </div>
        @if ($canEdit)
        <div class="cmp-fields">
            <label>Assignee<select name="assignee_user_id"><option value="">—</option>@foreach ($pickable as $u)<option value="{{ $u->id }}" @selected($e?->assignee_user_id === $u->id)>{{ $u->name }}</option>@endforeach</select></label>
            @if ($selfSign || $sig)
                <label>Completed by<input type="text" value="{{ $sig ? $sig->signerName() : $me->name.' (you)' }}" disabled></label>
            @else
            <label>Completed by<select name="completed_by_user_id"><option value="">{{ $done ? '—' : '(me, when checked)' }}</option>@foreach ($pickable as $u)<option value="{{ $u->id }}" @selected($e?->completed_by_user_id === $u->id)>{{ $u->name }}</option>@endforeach</select></label>
            @endif
            <label>Date<input type="date" name="completed_on" value="{{ $e?->completed_on?->toDateString() }}" @disabled($sig)></label>
            @if ($signMode !== 'name' && ! $sig)<label>Your signing {{ $signMode === 'pin' ? 'PIN' : 'password' }}<input type="password" name="signing_credential" autocomplete="off"></label>@endif
            <label>Note<textarea name="note" rows="1" maxlength="2000" placeholder="Optional">{{ $e?->note }}</textarea></label>
        </div>
        <div class="cmp-save"><button type="submit" class="pb-btn">{{ $sig ? 'Save assignee / note' : ($signMode !== 'name' ? 'Save / sign off' : 'Save') }}</button></div>
        @elseif ($e && ($e->assignee || $e->note))
        <div class="desc" style="margin-top:6px">@if ($e->assignee)Assignee: {{ $e->assignee->name }}. @endif @if ($e->note)Note: {{ $e->note }}@endif</div>
        @endif
    </form>
    @if ($pending && $canEdit && $pending->user_id === $me->id)
        <form method="post" action="{{ route('compliance.confirm', [$freq, $start->toDateString(), $it->id]) }}" class="cmp-confirm" style="margin:-4px 0 6px;padding:8px 12px;background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;font-size:13px">
            @csrf
            You revised this check-in. Confirm that it is now correct and it is signed again in your name, with the date and time.
            <button type="submit" class="pb-btn" id="confirm-{{ $it->id }}">Confirm my revision</button>
        </form>
    @endif
    @if ($canRevise)
        <details class="cmp-revise" style="margin:-4px 0 6px;padding:8px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;font-size:13px">
            <summary style="cursor:pointer">Revise {{ $sig->user_id === $me->id ? 'my' : 'this' }} check-in</summary>
            <form method="post" action="{{ route('compliance.save', [$freq, $start->toDateString(), $it->id]) }}" class="cmp-fields" style="margin-top:8px">
                @csrf
                <input type="hidden" name="revise" value="1">
                <input type="hidden" name="done" value="0">
                <label><span><input type="checkbox" name="done" value="1" checked> Done</span></label>
                <label>Date<input type="date" name="completed_on" value="{{ $e->completed_on?->toDateString() }}"></label>
                <label>Note<textarea name="note" rows="1" maxlength="2000">{{ $e->note }}</textarea></label>
                <label>Why are you revising it? (required)<input type="text" name="reason" required minlength="3" maxlength="1000"></label>
                <div class="pb-muted">Your original sign-off and this revision (who, when, old and new values) stay on record. Afterwards you confirm the revision. Nothing is deleted.</div>
                <div class="cmp-save"><button type="submit" class="pb-btn">Save revision</button></div>
            </form>
        </details>
    @endif
    @if ($revs->isNotEmpty())@include('partials.rec.revision_history', ['sigs' => $allSigs, 'revs' => $revs])@endif
    @if ($allSigs->isNotEmpty())<div class="cmp-sigs" style="margin:-4px 0 6px;padding:8px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;font-size:13px">@include('partials.rec.signatures', ['sigs' => $allSigs])</div>@endif
@endforeach
</div>
@endsection
