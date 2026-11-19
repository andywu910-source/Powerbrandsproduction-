@extends('layouts.app')
@section('title', 'Batch '.$b->batch_number)
@section('page_title', 'Batch '.$b->batch_number)
@section('parent_title', 'Batch Log')
@section('parent_url', route('batch-log.index'))
@section('content')
<section class="card">
    <div class="card-head"><h3>Post-batch summary</h3>
        <span class="{{ $b->logStatusClass() }}">{{ $s['status'] }}</span>@if ($b->isIncoming() && ! auth()->user()->isViewer()) <a class="pb-btn pb-btn-ghost" href="{{ route('production.index', ['start' => $b->batch_number]) }}">Start this job</a>@endif
        <span class="pb-spacer"></span>
        @if (in_array($b->status, ['in_process', 'in_qc'], true))<a class="pb-btn pb-btn-ghost" href="{{ route('production.show', $b) }}">Open in Production</a>@endif
        <a class="pb-btn pb-btn-ghost" href="{{ route('batch-log.print', $b) }}" target="_blank">Print</a>
        <a class="btn-save" href="{{ route('batch-log.pdf', $b) }}">Download PDF</a>
    </div>
    <p class="pb-muted">Built straight from the signed records. Nothing here can be edited; corrections are made on the record itself and show up here with the old value kept.</p>
    @include('batchlog.partials.summary', ['s' => $s, 'pdf' => false])
</section>
@php $logSigs = $b->signatures()->with('user')->where('meaning', 'log_signoff')->get(); $canLogSign = ! auth()->user()->isViewer() && $b->status !== 'void' && ! $b->isIncoming() && ! (auth()->user()->signsAsSelf() && $logSigs->where('user_id', auth()->id())->where('invalidated_at', null)->isNotEmpty()); @endphp
<section class="card" id="signoff">
    <div class="card-head"><h3>Batch log sign-off</h3>@if ($logSigs->where('invalidated_at', null)->isNotEmpty())<span class="pill ok">Signed off</span>@else<span class="pill warn">Not signed off yet</span>@endif</div>
    <p class="pb-muted">Signing off says you checked this batch log. It records your name and the date and time (Eastern), and it cannot be edited or deleted afterwards. Only an admin can void a sign-off, with a reason that is kept on record.</p>
    @include('partials.rec.signatures', ['sigs' => $logSigs])
    @if ($canLogSign)
        <form method="POST" action="{{ route('batch-log.signoff', $b) }}" class="pb-form">@csrf
            @include('partials.rec.sign', ['label' => 'Sign off this batch log', 'name' => 'signer_id', 'signers' => \App\Models\User::signers()])
            <div><button class="btn-save" type="submit">Sign off batch log</button></div>
        </form>
    @endif
</section>
@if ($b->source === 'sheet')
<section class="card" id="sheetImport">
    <div class="card-head"><h3>Imported from the Google Sheet</h3><span class="pill">{{ $b->import_id }}</span></div>
    <p>{{ $b->import_note }}</p>
    @if ($b->legacy_batch_id && $b->legacy_batch_id !== $b->batch_number)<p class="pb-muted">Batch ID in the sheet: <b class="pb-bn">{{ $b->legacy_batch_id }}</b></p>@endif
    <div class="pb-kv">
        @foreach ((array) $b->sheet_fields as $k => $v) @if (trim((string) $v) !== '')<div><span>{{ config('pbp_sheet_import.labels.'.$k, ucfirst(str_replace('_', ' ', $k))) }}</span><b>{{ $v }}</b></div>@endif @endforeach
    </div>
</section>
@endif
@php $canEditRet = auth()->user()->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_SUPERVISOR); $per = $b->labSamplesPerTest(); @endphp
<section class="card" id="retention">
    <div class="card-head"><h3>OCM samples and retention</h3><span class="pb-muted" title="{{ \App\Support\OcmSampling::INFO }}">ⓘ OCM Sampling QS Standard</span></div>
    <div class="pb-kv">
        <div><span>Units planned</span><b>{{ $b->target_units === null ? '—' : number_format($b->target_units) }}</b></div>
        <div><span>Serving type</span><b>{{ $b->servingType() === 'multi' ? 'Multi-serving' : 'Single-serve' }}</b></div>
        <div><span>Lab samples (per test)</span><b id="retPer">{{ $per ?? ($b->target_units ? 'Over 150,000 units' : '—') }}</b></div>
        <div><span>Retention samples needed</span><b id="retNeed">{{ $b->retentionSamples() ?? '—' }}</b></div>
        <div><span>Expiration date</span><b>{{ \App\Support\Fmt::date($b->expiration_date) ?: '—' }}</b></div>
        <div><span>Retain until</span><b id="retUntil">{{ \App\Support\Fmt::date($b->retainUntil()) ?: 'Set the expiration date' }}</b></div>
    </div>
    <p class="pb-muted">{{ \App\Support\OcmSampling::INFO }}</p>
    @if ($canEditRet)
        <form method="POST" action="{{ route('batch-log.expiration', $b) }}" class="pb-inline-form">@csrf
            <div class="f-field"><label for="expiration_date">Expiration date</label><input id="expiration_date" class="pb-input" type="date" name="expiration_date" value="{{ old('expiration_date', $b->expiration_date?->toDateString()) }}"></div>
            <button class="btn-save" type="submit">Save expiration date</button>
        </form>
    @endif
</section>
@php $canBarcode = auth()->user()->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_SUPERVISOR); $needs = (bool) $b->product->brand?->requires_barcode; @endphp
<section class="card" id="barcode">
    <div class="card-head"><h3>Barcode number</h3>
        @if ($needs)<span class="pill {{ $b->barcode_number ? 'ok' : 'warn' }}">{{ $b->barcode_number ? 'Set' : 'Missing' }} · {{ $b->product->brand->name }} labels need a barcode</span>@else<span class="pb-muted">{{ $b->product->brand?->name ?? 'This brand' }} labels don't print a barcode</span>@endif</div>
    <p class="pb-muted">Printed as a Code 128 barcode on this batch's case labels. Every change is kept in the audit log with the old and new value.</p>
    @if ($canBarcode)
        <form method="POST" action="{{ route('batch-log.barcode', $b) }}" class="pb-inline-form">@csrf
            <div class="f-field"><label for="barcode_number">Barcode number</label>
                <div class="pb-scan-wrap"><input id="barcode_number" class="pb-input pb-bn" name="barcode_number" value="{{ old('barcode_number', $b->barcode_number) }}" maxlength="48" autocomplete="off" spellcheck="false" placeholder="e.g. 1A4120300000596000001707" data-scan="barcode">@include('partials.scan', ['for' => 'barcode_number', 'label' => 'Barcode number'])</div>
</div>
            <button class="btn-save" type="submit">Save barcode number</button>
            @if (\App\Support\Modules::enabled('labels'))<a class="pb-btn pb-btn-ghost" href="{{ route('labels.create', ['batch_id' => $b->id]) }}">Labels</a>@endif
        </form>
    @else
        <p><b class="pb-bn">{{ $b->barcode_number ?: '—' }}</b> <span class="pb-muted">(an admin or supervisor can change it)</span></p>
    @endif
</section>
@endsection
