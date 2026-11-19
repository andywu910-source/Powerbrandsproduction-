@extends('layouts.app')
@section('title', 'Compliance')
@section('page_title', 'Compliance')
@push('styles')<link rel="stylesheet" href="{{ asset('css/logs.css') }}?v={{ @filemtime(public_path('css/logs.css')) }}">@endpush
@php $pill = ['done' => 'ok', 'due' => 'warn', 'overdue' => 'bad', 'as_needed' => 'muted']; @endphp
@section('content')
<div class="lg-strip" id="lgStrip">
    <a href="{{ route('logs.index', ['due' => 'overdue']) }}" class="lg-stat bad {{ $due === 'overdue' ? 'on' : '' }}" data-due="overdue"><b id="lgCountOverdue">{{ $counts['overdue'] }}</b><span>Overdue</span></a>
    <a href="{{ route('logs.index', ['due' => 'today']) }}" class="lg-stat warn {{ $due === 'today' ? 'on' : '' }}" data-due="today"><b id="lgCountToday">{{ $counts['today'] }}</b><span>Due today</span></a>
    <a href="{{ route('logs.index', ['due' => 'week']) }}" class="lg-stat {{ $due === 'week' ? 'on' : '' }}" data-due="week"><b id="lgCountWeek">{{ $counts['week'] }}</b><span>Due this week</span></a>
    <a href="{{ route('logs.index') }}" class="lg-stat muted {{ $due === 'all' ? 'on' : '' }}" data-due="all"><b>{{ $counts['all'] }}</b><span>Logs</span></a>
    <a href="{{ route('logs.queue') }}" class="lg-stat verify" id="lgVerifyStat" title="Entries waiting for an admin to verify them"><b id="lgCountPending">{{ $pending }}</b><span>Needs verification</span></a>
</div>

<form class="lg-tools" method="get" action="{{ route('logs.index') }}" id="lgTools" role="search">
    <div class="cmp-tabs lg-filter" role="group" aria-label="Due filter">
        @foreach (['all' => 'All', 'overdue' => 'Overdue', 'today' => 'Today', 'week' => 'This week'] as $k => $lbl)
            <a href="{{ route('logs.index', array_filter(['due' => $k === 'all' ? null : $k, 'q' => $q ?: null, 'finding' => $finding ?: null])) }}" data-due="{{ $k }}" class="{{ $due === $k ? 'on' : '' }}">{{ $lbl }}</a>
        @endforeach
        <a href="{{ route('logs.queue') }}" class="lg-queue-link" id="lgQueueLink">Needs verification ({{ $pending }})</a>
    </div>
    <input type="hidden" name="due" value="{{ $due === 'all' ? '' : $due }}" id="lgDue">
    <input type="search" name="q" value="{{ $q }}" id="lgSearch" class="pb-input" placeholder="Search log name or code (e.g. 2-E, visitor)" autocomplete="off" aria-label="Search logs">
    <select name="finding" id="lgFinding" class="pb-select" aria-label="OCM finding">
        <option value="">All OCM findings</option>
        @foreach ($findings as $f)
            <option value="{{ $f === '' ? 'none' : $f }}" @selected($finding === ($f === '' ? 'none' : $f))>{{ $f === '' ? 'No OCM code' : ($f === 'T-1' ? 'T-1 (training)' : 'Finding '.$f) }}</option>
        @endforeach
    </select>
    <noscript><button class="pb-btn" type="submit">Filter</button></noscript>
</form>

<div id="lgGroups">
@foreach ($groups as $n => $g)
    <section class="card lg-group" data-group="{{ $g['key'] }}">
        <div class="card-head"><h3>{{ $n + 1 }}. {{ $g['label'] }}</h3>@if ($g['managers'])<span class="pill muted lg-mgr">Managers only</span>@endif</div>
        <div class="table-wrap"><table class="pb-table lg-table">
            <thead><tr><th>Code</th><th>Log</th><th>How often</th><th>Status</th><th>Last entry</th></tr></thead>
            <tbody>
            @foreach ($g['rows'] as $r)
                @php $d = $r['def']; $s = $r['status']; @endphp
                <tr class="lg-row" data-name="{{ mb_strtolower($d->code.' '.$d->name) }}" data-finding="{{ $d->finding ?? 'none' }}"
                    data-state="{{ $s['state'] }}" data-today="{{ $s['today'] ? 1 : 0 }}" data-week="{{ $s['week'] ? 1 : 0 }}">
                    <td><a href="{{ route('logs.show', $d) }}" class="lg-code">{{ $d->code }}</a></td>
                    <td><a href="{{ route('logs.show', $d) }}"><b>{{ $d->name }}</b></a>
                        @unless ($d->confirmed)<span class="pill warn lg-tag" title="No Drive sheet found: placeholder fields">Fields unconfirmed</span>@endunless
                        @if ($d->partial)<span class="pill muted lg-tag" title="Only part of the sheet's headers are mapped">Partial</span>@endif</td>
                    <td>{{ $d->frequencyLabel() }}</td>
                    <td><span class="pill {{ $pill[$s['state']] }}">{{ $s['label'] }}</span></td>
                    <td class="pb-muted">{{ $s['last'] ? \Carbon\Carbon::parse($s['last'])->format('M j, Y') : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
@endforeach
</div>
<p class="pb-muted" id="lgEmpty" hidden>No logs match.</p>
@endsection

@push('scripts')
<script>
(function () {
    var q = document.getElementById('lgSearch'), f = document.getElementById('lgFinding'), dueIn = document.getElementById('lgDue');
    var due = dueIn.value || 'all';
    function apply() {
        var term = q.value.trim().toLowerCase(), fv = f.value, shown = 0;
        document.querySelectorAll('.lg-group').forEach(function (g) {
            var any = 0;
            g.querySelectorAll('.lg-row').forEach(function (r) {
                var ok = (!term || r.dataset.name.indexOf(term) !== -1) && (!fv || r.dataset.finding === fv)
                    && (due === 'all' || (due === 'overdue' && r.dataset.state === 'overdue') || (due === 'today' && r.dataset.today === '1') || (due === 'week' && r.dataset.week === '1'));
                r.hidden = !ok; if (ok) any++;
            });
            g.hidden = !any; shown += any;
        });
        document.getElementById('lgEmpty').hidden = shown > 0;
    }
    q.addEventListener('input', apply);
    f.addEventListener('change', apply);
    document.querySelectorAll('[data-due]').forEach(function (a) {
        if (a.tagName !== 'A') return;
        a.addEventListener('click', function (e) {
            e.preventDefault(); due = a.dataset.due; dueIn.value = due === 'all' ? '' : due;
            document.querySelectorAll('[data-due]').forEach(function (b) { b.classList.toggle('on', b.dataset.due === due); });
            history.replaceState(null, '', a.href); apply();
        });
    });
    apply();
})();
</script>
@endpush
