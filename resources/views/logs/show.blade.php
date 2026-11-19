@extends('layouts.app')
@section('title', $log->code.' '.$log->name)
@section('page_title', 'Compliance')
@push('styles')<link rel="stylesheet" href="{{ asset('css/logs.css') }}?v={{ @filemtime(public_path('css/logs.css')) }}">@endpush
@php
    use App\Support\LogHub; use App\Support\Fmt;
    $pill = ['done' => 'ok', 'due' => 'warn', 'overdue' => 'bad', 'as_needed' => 'muted'];
    $cols = $log->tableFields();
@endphp
@section('content')
<p class="lg-back"><a href="{{ route('logs.index') }}">‹ All logs</a> <span class="pb-muted">/ {{ $log->groupLabel() }}</span></p>
<div class="lg-head">
    <span class="lg-code lg-code-lg">{{ $log->code }}</span>
    <h3>{{ $log->name }}</h3>
    <span class="pill {{ $pill[$status['state']] }}" id="lgStatus">{{ $status['label'] }}</span>
    <span class="pb-muted">{{ $log->frequencyLabel() }} · {{ $log->findingLabel() }}@if ($log->manager_only) · Managers only @endif</span>
    @if ($log->drive_url)<a class="pb-btn pb-btn-ghost btn-sm" href="{{ $log->drive_url }}" target="_blank" rel="noopener">Drive sheet ↗</a>@endif
</div>
@if ($retired)
    <div class="pb-alert pb-alert-warn" id="lgRetired"><b>Retired log.</b> {{ $retiredWhy }} New entries can't be added here; past entries stay below for the record.</div>
@endif
@unless ($log->confirmed)
    <div class="pb-alert pb-alert-warn" id="lgUnconfirmed">No Drive sheet was found for this log, so these fields are placeholders. Confirm the columns before using it for OCM records.</div>
@endunless
@if ($log->partial)<div class="pb-alert pb-alert-warn">Only part of the Drive sheet's headers are mapped so far.</div>@endif
@if ($log->note)<p class="cmp-note">{{ $log->note }}</p>@endif
@if (! empty($status['missing']))
    <div class="pb-alert pb-alert-warn" id="lgMissing">No entry yet for batch{{ count($status['missing']) === 1 ? '' : 'es' }}: {{ implode(', ', array_slice($status['missing'], 0, 12)) }}{{ count($status['missing']) > 12 ? ' …' : '' }}</div>
@endif

@unless ($retired)
<div class="lg-cols">
    <section class="card lg-form-card">
        <div class="card-head"><h3>New entry</h3><span class="pb-spacer"></span><span class="pb-muted">Your name and the time are recorded automatically.</span></div>
        @if ($canWrite)
        <form method="post" action="{{ route('logs.store', $log) }}" enctype="multipart/form-data" class="lg-form" id="lgForm">
            @csrf
            <div class="lg-grid">
                @foreach (LogHub::formFields($log) as $f)
                    @include('logs._field', ['f' => $f, 'log' => $log])
                @endforeach
            </div>
            <div class="form-actions"><button type="submit" class="pb-btn">Save entry</button></div>
        </form>
        @else
            <p class="pb-muted">Read-only account.</p>
        @endif
    </section>
    @include('logs._related')
</div>
@endunless

<section class="card" id="lgEntries">
    <div class="card-head"><h3>Recent entries</h3><span class="pb-spacer"></span><span class="pb-muted">Last 25</span></div>
    <div class="table-wrap"><table class="pb-table lg-entries">
        <thead><tr><th>#</th>@foreach ($cols as $c)<th>{{ $c['label'] }}</th>@endforeach<th>Recorded by</th><th>Recorded at</th><th>Verification</th><th></th></tr></thead>
        <tbody>
        @forelse ($entries as $e)
            <tr class="{{ $e->voided_at ? 'lg-void' : '' }} {{ $selected && $selected->id === $e->id ? 'lg-sel' : '' }}">
                <td>{{ $e->id }}</td>
                @foreach ($cols as $c)<td>{{ LogHub::display($c, $e->value($c['key'])) }}</td>@endforeach
                <td>{{ $e->enteredBy?->name }}</td>
                <td class="pb-nowrap">{{ Fmt::dt($e->created_at) }}</td>
                @php [$vc, $vl] = $e->verificationBadge(); @endphp
                <td class="pb-nowrap"><span class="pill {{ $vc }} lg-vbadge" @if ($e->voided_at) title="{{ $e->void_reason }}" @endif>{{ $vl }}</span></td>
                <td class="pb-nowrap"><a href="{{ route('logs.show', [$log, 'entry' => $e->id]) }}#lgDetail">Details</a></td>
            </tr>
        @empty
            <tr><td colspan="{{ count($cols) + 5 }}" class="pb-muted">No entries yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

@if ($selected)
<section class="card" id="lgDetail">
    @php [$vc, $vl] = $selected->verificationBadge(); @endphp
    <div class="card-head"><h3>Entry #{{ $selected->id }}</h3><span class="pill {{ $vc }} lg-vbadge" id="lgVerifyBadge">{{ $vl }}</span><span class="pb-spacer"></span><span class="pb-muted">Recorded by {{ $selected->enteredBy?->name }} · {{ Fmt::dt($selected->created_at) }}</span></div>
    @if ($selected->voided_at)<div class="pb-alert pb-alert-err">{{ $selected->verification_status === 'returned' ? 'Returned' : 'Void' }}: {{ $selected->void_reason }}</div>@endif
    @if ($selected->verification_status === 'verified')<p class="pb-muted" id="lgVerifiedBy">Verified by {{ $selected->verifiedBy?->name }} · {{ Fmt::dt($selected->verified_at) }}</p>@endif
    @if ($canVerify && $selected->isPendingVerification())
        @if ((int) $selected->entered_by_user_id === (int) auth()->id())
            <p class="pb-alert pb-alert-warn" id="lgOwnEntry">You entered this entry, so another admin has to verify it.</p>
        @else
            <div class="lg-verify" id="lgVerify">
                <form method="post" action="{{ route('logs.verify', $selected) }}" class="pb-inline-form">@csrf
                    @if ($signMode !== 'name')<input type="password" name="signing_credential" class="pb-input" autocomplete="off" placeholder="Your signing {{ $signMode === 'pin' ? 'PIN' : 'password' }}" required>@endif
                    <button class="pb-btn" type="submit">Verify (sign as {{ auth()->user()->name }})</button>
                </form>
                <details class="pb-details"><summary>Send back</summary>
                    <form method="post" action="{{ route('logs.send-back', $selected) }}" class="pb-inline-form">@csrf
                        <input name="reason" class="pb-input" required maxlength="1000" placeholder="What needs fixing (required)"><button class="pb-btn pb-btn-ghost">Send back</button></form>
                    <p class="pb-muted">The entry is voided with your reason and marked Returned; a corrected entry is recorded as a new one.</p>
                </details>
            </div>
        @endif
    @endif
    <dl class="lg-kv">
        @foreach ($log->fields as $f)
            @continue(! empty($f['removed']) && ($selected->value($f['key']) === null || $selected->value($f['key']) === ''))
            <dt>{{ $f['label'] }}@if (! empty($f['removed'])) <span class="pb-muted lg-removed">(no longer asked)</span>@endif</dt>
            <dd>@if ($f['type'] === 'file' && is_array($selected->value($f['key'])))<a href="{{ route('files.download', $selected->value($f['key'])['attachment_id']) }}">{{ $selected->value($f['key'])['filename'] }}</a>@else{{ LogHub::display($f, $selected->value($f['key'])) ?: '—' }}@endif</dd>
        @endforeach
    </dl>
    @include('partials.rec.signatures', ['sigs' => $selected->signatures])
    @if ($log->code === 'CMP' && $canWrite && ! $selected->voided_at)
        <p id="lgCmpDeviation"><a class="pb-btn pb-btn-ghost btn-sm" href="{{ route('deviations.index', ['new' => 1, 'origin' => 'complaint', 'origin_ref' => 'CMP entry #'.$selected->id]) }}#devNew">Open a deviation / CAPA for this complaint</a></p>
    @endif
    @if ($canVoid && ! $selected->voided_at)
        <details class="pb-details pb-details-danger"><summary>Void this entry</summary>
            <form method="post" action="{{ route('logs.void', $selected) }}" class="pb-inline-form">@csrf
                <input name="reason" class="pb-input" required maxlength="1000" placeholder="Reason (required)"><button class="pb-btn pb-btn-ghost">Void</button></form>
        </details>
    @endif
</section>
@endif

<datalist id="lgEmployees">@foreach ($lists['employee'] as $n)<option value="{{ $n }}">@endforeach</datalist>
<datalist id="lgRooms">@foreach ($lists['room'] as $r)<option value="{{ $r->name }}">{{ $r->room_no }}</option>@endforeach</datalist>
<datalist id="lgEquipment">@foreach ($lists['equipment'] as $q)<option value="{{ $q->equip_id }}">{{ $q->name }}</option>@endforeach</datalist>
<datalist id="lgBatches">@foreach ($lists['batch'] as $b)<option value="{{ $b }}">@endforeach</datalist>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.lg-metrc').forEach(function (i) {
    var c = document.querySelector('.lg-count[data-for="' + i.id + '"]');
    function upd() { var v = i.value.replace(/\s+/g, ''); c.textContent = v.length + ' / 24'; c.classList.toggle('lg-bad', v.length > 0 && v.length !== 24); }
    i.addEventListener('input', upd); upd();
});
// A field that is required only when a checkbox is ticked stays hidden until then (e.g. 4-A "Wrong location note").
document.querySelectorAll('[data-show-if]').forEach(function (w) {
    var box = document.getElementById(w.getAttribute('data-show-if')), inp = w.querySelector('input, textarea, select');
    if (!box || !inp) return;
    function upd() { w.style.display = box.checked ? '' : 'none'; inp.required = box.checked; if (!box.checked) inp.value = ''; }
    box.addEventListener('change', upd); upd();
});
</script>
@endpush
