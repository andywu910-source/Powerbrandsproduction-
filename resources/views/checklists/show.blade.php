@extends('layouts.app')
@section('title', $i->public_id)
@section('page_title', $i->template->name)
@if (\App\Support\Modules::enabled('checklists'))
    @section('parent_title', 'Checklists')
    @section('parent_url', route('checklists.index'))
@endif
@php use App\Support\Fmt; $retiredTo = \App\Support\Retirements::replacedBy($i->template); $ro = auth()->user()->isViewer() || ! $i->isOpen() || $retiredTo; $st = $i->displayStatus(); @endphp

@section('content')
@if ($retiredTo)
    <div class="pb-alert pb-alert-warn" id="chkRetired">This checklist was retired (it duplicates Compliance hub log {{ $retiredTo }}). It is kept for the record; @if ($i->status === 'awaiting_verification') it can still be verified. @elseif (\App\Support\Retirements::replacementActive($retiredTo)) record new entries in the <a href="{{ route('logs.index', ['q' => $retiredTo]) }}">Compliance hub</a>. @else that hub log was retired too, so nothing new is recorded. @endif</div>
@endif
<section class="card">
    <div class="card-head"><b>{{ $i->public_id }}</b>
        <span class="pill {{ Fmt::pill($st) }}">{{ ucfirst(str_replace('_', ' ', $st)) }}</span>
        @include('partials.rec.filing', ['record' => $i])
        <span class="pb-spacer"></span>
        @if ($i->batch)<a class="pb-btn pb-btn-ghost" href="{{ route('production.show', $i->batch) }}">Batch {{ $i->batch->batch_number }}</a>@endif
    </div>
    <div class="pb-kv">
        <div><span>Checklist</span><b>{{ $i->template->name }} (v{{ $i->version->version_no }})</b></div>
        @if ($sc = $i->scopedEquipment())
            <div><span>{{ $sc->is_scale ? 'Scale checked' : 'Equipment' }}</span><b>{{ $sc->equip_id }} · {{ $sc->name }}@if ($sc->is_scale) <span class="pill {{ \App\Models\Equipment::statusPill($sc->inspectionStatus()) }}">{{ \App\Models\Equipment::statusLabel($sc->inspectionStatus()) }}</span>@endif</b></div>
        @elseif ($i->scope_label)
            <div><span>For</span><b>{{ $i->scope_label }}</b></div>
        @endif
        <div><span>Due</span><b>{{ Fmt::dt($i->due_at) ?: 'when needed' }}</b></div>
        <div><span>Who fills it in</span><b>{{ ucfirst($i->version->completed_by_role) }}</b></div>
        <div><span>Second-person check</span><b>{{ $i->version->verifier_role ? ucfirst($i->version->verifier_role) : 'Not needed' }}</b></div>
        @if ($i->completed_at)<div><span>Completed</span><b>{{ Fmt::dt($i->completed_at) }}{{ $i->performer ? ' by '.$i->performer->full_name : '' }}</b></div>@endif
    </div>
</section>

<section class="card">
    <form method="POST" action="{{ route('checklists.complete', $i) }}" id="chkForm" class="pb-form">
        @csrf
        <div class="table-wrap"><table class="pb-table">
            <thead><tr><th>#</th><th>Check</th><th>Answer</th><th>Note (needed if it fails)</th></tr></thead>
            <tbody>
            @foreach ($i->items as $it)
                @php $ti = $it->templateItem; $n = "items[{$it->id}]"; @endphp
                <tr>
                    <td>{{ $ti->item_no }}</td>
                    <td>{{ $ti->prompt }} @if ($ti->sop_ref)<span class="pb-muted">({{ $ti->sop_ref }})</span>@endif
                        @if ($ti->response_type === 'numeric' && ($ti->min !== null || $ti->max !== null))<br><span class="pill blue">{{ (float) $ti->min }}–{{ (float) $ti->max }} {{ $ti->unit }}</span>@endif
                        @if ($it->within_limit === false)<br><span class="pill bad">Failed</span>@endif</td>
                    <td>
                    @if ($ro)
                        <b>{{ $it->response }}</b> <span class="pb-muted">{{ $it->answeredBy?->name }} {{ Fmt::dt($it->answered_at) }}</span>
                    @elseif (in_array($ti->response_type, ['pass_fail', 'yes_no'], true))
                        @php $opts = $ti->response_type === 'pass_fail' ? ['pass' => 'Pass', 'fail' => 'Fail'] : ['yes' => 'Yes', 'no' => 'No']; @endphp
                        @foreach ($opts as $v => $l)<label class="pb-check-inline"><input type="radio" name="{{ $n }}[response]" value="{{ $v }}" @checked($it->response === $v)> {{ $l }}</label> @endforeach
                    @elseif ($ti->response_type === 'numeric')
                        <input class="pb-input" type="number" step="any" name="{{ $n }}[response]" value="{{ $it->response }}">
                    @else
                        <input class="pb-input" name="{{ $n }}[response]" value="{{ $it->response }}">
                    @endif
                    </td>
                    <td>
                    @if ($ro){{ $it->note }}@else<input class="pb-input" name="{{ $n }}[note]" value="{{ $it->note }}">@endif
                    @if ($it->deviation)<a href="{{ route('deviations.show', $it->deviation) }}">{{ $it->deviation->public_id }}</a>@endif
                    @include('partials.rec.revisions', ['revisions' => $it->revisions])
                    </td>
                </tr>
            @endforeach
            </tbody></table></div>
        @unless ($ro)
            <div class="pb-row"><button class="pb-btn pb-btn-ghost" type="submit" formaction="{{ route('checklists.answers', $i) }}" formnovalidate>Save answers (sign later)</button></div>
            @include('partials.rec.problems', ['problems' => $problems])
            <div class="pb-grid">
                <div class="f-field"><label>Done by</label><select name="performed_by_person_id" class="pb-select" required><option value="">— pick —</option>
                    @foreach ($people->where('no_documentation', false) as $p)<option value="{{ $p->id }}">{{ $p->full_name }}</option>@endforeach</select></div>
                <div class="f-field"><label>If filled in late: why</label><input class="pb-input" name="late_entry_reason"></div>
                @include('partials.rec.sign', ['label' => 'Completed and signed by'])
            </div>
            <div><button class="btn-save" type="submit">Sign as complete</button>
                <span class="pb-muted">Signature and date are blank until you sign.</span></div>
        @endunless
    </form>
    @include('partials.rec.signatures', ['sigs' => $i->signatures])

    @if ($i->status === 'awaiting_verification' && ! auth()->user()->isViewer())
        <form method="POST" action="{{ route('checklists.verify', $i) }}" class="pb-form" style="margin-top:12px">@csrf
            @include('partials.rec.sign', ['label' => 'Verified by (a different person)'])
            <div><button class="btn-save" type="submit">Verify</button></div></form>
    @endif

    @if (! $ro)
        @foreach ($i->items->filter(fn ($it) => $it->within_limit === false && ! $it->deviation_id) as $it)
            <details class="pb-details"><summary>Item {{ $it->templateItem->item_no }} failed: open a deviation</summary>
                <form method="POST" action="{{ route('checklists.deviation', [$i, $it->id]) }}" class="pb-form">@csrf
                    <textarea name="description" rows="2" placeholder="What was wrong"></textarea>@include('partials.rec.sign', ['label' => 'Reported by'])
                    <button class="pb-btn pb-btn-ghost" type="submit">Open deviation</button></form></details>
        @endforeach
    @endif
</section>
@endsection
