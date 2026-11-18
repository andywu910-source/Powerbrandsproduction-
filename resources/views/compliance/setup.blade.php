@extends('layouts.app')
@section('title', 'Compliance · Items')
@section('page_title', 'Compliance Checklists')
@section('content')
@include('compliance._nav')
@unless ($isAdmin)<div class="cmp-ro">Only admins can add, rename, reorder or hide items.</div>@endunless
@foreach (\App\Models\ComplianceItem::FREQUENCIES as $f => $label)
<section class="card">
    <div class="card-head"><h3>{{ $label }} items</h3><span class="pb-muted">Hidden items drop off new periods; history keeps them.</span></div>
    <div class="cmp-list">
    @foreach ($items[$f] ?? [] as $n => $it)
        @if ($isAdmin)
        <form method="post" action="{{ route('compliance.item.save', $it) }}" class="cmp-item {{ $it->hidden ? 'is-void' : '' }}">@csrf
            <div class="cmp-fields">
                <label class="wide">{{ $n + 1 }}. Title<input name="title" value="{{ $it->title }}" maxlength="160" required></label>
                <label class="wide">Description<textarea name="description" rows="2">{{ $it->description }}</textarea></label>
                @if ($it->standing)<label><span class="pb-muted">Standing log (confirmed each year)</span></label>@endif
                <label><span><input type="hidden" name="hidden" value="0"><input type="checkbox" name="hidden" value="1" @checked($it->hidden) style="width:auto;min-height:0"> Hidden</span></label>
            </div>
            <div class="cmp-save"><button class="pb-btn" name="move" value="up" formnovalidate>↑</button> <button class="pb-btn" name="move" value="down" formnovalidate>↓</button> <button type="submit" class="pb-btn">Save</button></div>
        </form>
        @else
        <div class="cmp-item"><div class="ttl">{{ $n + 1 }}. {{ $it->title }}@if ($it->standing) (standing log)@endif @if ($it->hidden) (hidden)@endif</div>@if ($it->description)<div class="desc">{{ $it->description }}</div>@endif</div>
        @endif
    @endforeach
    </div>
    @if ($isAdmin)
    <form method="post" action="{{ route('compliance.item.add') }}" class="cmp-item" style="margin-top:10px">@csrf<input type="hidden" name="frequency" value="{{ $f }}">
        <div class="cmp-fields"><label class="wide">New {{ strtolower($label) }} item<input name="title" maxlength="160" required></label><label class="wide">Description<textarea name="description" rows="1"></textarea></label>
            @if ($f === 'annual')<label><span><input type="hidden" name="standing" value="0"><input type="checkbox" name="standing" value="1" style="width:auto;min-height:0"> Standing log</span></label>@endif</div>
        <div class="cmp-save"><button type="submit" class="pb-btn">Add item</button></div>
    </form>
    @endif
</section>
@endforeach
@endsection
