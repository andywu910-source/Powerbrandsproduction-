@extends('layouts.app')
@section('title', 'Scale register')
@section('page_title', 'Scale register')
@section('parent_title', 'Setup')
@section('parent_url', route('setup.home'))
@php use App\Models\Equipment; use App\Support\Fmt; @endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>Scales</h3><span class="pb-spacer"></span>
        <span class="pb-muted">Inspected at least every {{ Equipment::inspectionMonths() }} months · flagged {{ Equipment::dueSoonDays() }} days ahead</span></div>
    <div class="pb-alert {{ \App\Services\ScaleBlock::on() ? 'pb-alert-ok' : 'pb-alert-warn' }}" id="scaleBlockStatus">{{ \App\Services\ScaleBlock::statusLine() }}</div>
    <div class="pb-chipbar">
        @foreach (['overdue' => 'Overdue', 'missing' => 'No inspection on file', 'due_soon' => 'Due soon', 'ok' => 'In date', 'inactive' => 'Not in use'] as $k => $l)
            @if ($counts[$k] ?? 0)<span class="pill {{ Equipment::statusPill($k) }}">{{ $counts[$k] }} {{ $l }}</span>@endif
        @endforeach
    </div>
    <div class="table-wrap"><table class="pb-table pb-table-cards">
        <thead><tr><th>Scale</th><th>Serial</th><th>Location</th><th>Capacity / d</th><th>Seal #</th><th>Last inspection</th><th>Next due</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($scales as $s)
            @php $st = $s->inspectionStatus(); @endphp
            <tr class="pb-flag-row {{ Equipment::statusPill($st) }}">
                <td data-th="Scale"><b>{{ $s->equip_id }}</b> · {{ $s->name }}</td>
                <td data-th="Serial">{{ $s->serial }}</td>
                <td data-th="Location">{{ trim(($s->room ? 'Room '.$s->room->room_no : '').($s->location ? ' · '.$s->location : ''), ' ·') }}</td>
                <td data-th="Capacity / d">@if ($s->capacity){{ rtrim(rtrim(number_format($s->capacity, 4, '.', ''), '0'), '.') }} {{ $s->capacity_unit }}@endif @if ($s->division) / {{ rtrim(rtrim(number_format($s->division, 6, '.', ''), '0'), '.') }} {{ $s->capacity_unit }}@endif</td>
                <td data-th="Seal #">{{ $s->wm_seal_ref }}</td>
                <td data-th="Last inspection">{{ Fmt::date($s->last_inspected_on) }}</td>
                <td data-th="Next due">{{ Fmt::date($s->inspection_due_on) }}</td>
                <td data-th="Status"><span class="pill {{ Equipment::statusPill($st) }}">{{ Equipment::statusLabel($st) }}</span></td>
                <td><a class="pb-btn pb-btn-ghost" href="{{ route('scales.show', $s) }}">{{ $canEdit ? 'Open' : 'View' }}</a></td></tr>
        @empty
            <tr><td colspan="9" class="pb-muted">No scales yet. Add each scale below; Production step 3 only accepts scales in this register.</td></tr>
        @endforelse
        </tbody></table></div>
</section>
@if ($canEdit)
<section class="card pb-form-card">
    <div class="card-head"><h3>Add a scale</h3></div>
    <form method="POST" action="{{ route('scales.store') }}" class="pb-form">@csrf
        @include('scales._fields', ['scale' => $scale, 'disabled' => false])
        <div><button class="btn-save" type="submit">Add scale</button></div>
    </form>
</section>
@endif
@endsection
