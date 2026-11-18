@extends('layouts.app')
@section('title', 'Batch Log')
@section('page_title', 'Batch Log')
@php use App\Support\Fmt; @endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>Batches</h3><span class="pb-muted">Every batch, with its full record. Change a batch's status with its Status menu; open one to print or download the PDF.</span></div>
    <form method="GET" class="pb-inline-form">
        <div class="f-field"><label>Search</label><input class="pb-input" name="q" value="{{ request('q') }}" placeholder="Batch number, BPR, barcode or Metrc UID"></div>
        <div class="f-field"><label>Status</label><select name="status" class="pb-select">
            @foreach (['all' => 'All (not void)'] + \App\Models\Batch::LOG_STATUSES + ['void' => 'Void', 'everything' => 'All, including void'] as $k => $l)
                <option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="f-field"><label>Product</label><select name="product_id" class="pb-select"><option value="">All</option>
            @foreach ($products as $p)<option value="{{ $p->id }}" @selected((int) request('product_id') === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
        <div class="f-field"><label>From</label><input class="pb-input" type="date" name="from" value="{{ request('from') }}"></div>
        <div class="f-field"><label>To</label><input class="pb-input" type="date" name="to" value="{{ request('to') }}"></div>
        <button class="pb-btn pb-btn-ghost">Filter</button>
    </form>
@php $H = \App\Support\BatchLogHelp::COLUMNS; $O = \App\Support\OcmSampling::INFO; $canEdit = ! auth()->user()->isViewer(); @endphp
    <div class="table-wrap" style="margin-top:12px"><table class="pb-table" id="batchLogTable">
        <thead><tr>
            <th>Batch number @include('partials.tip', ['label' => 'Batch number', 'text' => $H['batch_number']])</th><th>Product @include('partials.tip', ['label' => 'Product', 'text' => $H['product']])</th><th>Brand @include('partials.tip', ['label' => 'Brand', 'text' => $H['brand']])</th>
            <th>Barcode number @include('partials.tip', ['label' => 'Barcode number', 'text' => $H['barcode']])</th>
            <th>Units planned @include('partials.tip', ['label' => 'Units planned', 'text' => $H['units']])</th>
            <th>Lab samples (per test) @include('partials.tip', ['label' => 'Lab samples (per test)', 'text' => $H['lab_samples']])</th>
            <th>Retention samples needed @include('partials.tip', ['label' => 'Retention samples needed', 'text' => $H['retention']])</th>
            <th>Status @include('partials.tip', ['label' => 'Status', 'text' => $H['status']])</th></tr></thead>
        <tbody>
        @forelse ($batches as $b)
            <tr class="{{ $b->status === 'void' ? 'is-void' : '' }}">
                <td><a class="pb-bn" href="{{ route('batch-log.show', $b) }}"><b>{{ $b->batch_number }}</b></a>@if ($b->status === 'void') <span class="pill bad">Void</span>@endif<br><span class="pb-muted">{{ $b->public_id }}</span></td>
                <td data-th="Product">{{ $b->product->name }}</td><td data-th="Brand">{{ $b->product->brand?->name }}</td>
                <td data-th="Barcode number">@if ($b->barcode_number)<span class="pb-bn">{{ $b->barcode_number }}</span>@elseif ($b->product->brand?->requires_barcode)<span class="pill warn">Missing</span>@else<span class="pb-muted">—</span>@endif</td>
                <td data-th="Units planned">{{ $b->target_units === null ? '—' : number_format($b->target_units) }}</td>
                @php $per = $b->labSamplesPerTest(); $ret = $b->retentionSamples(); @endphp
                <td data-th="Lab samples (per test)" class="js-lab">@if ($per !== null){{ $per }} <span class="pb-muted">{{ $b->servingType() === 'multi' ? 'multi-serving' : 'single-serve' }}</span>@else<span class="pill bad">Over 150,000</span>@endif</td>
                <td data-th="Retention samples needed" class="js-ret">{{ $ret ?? '—' }}</td>
                <td data-th="Status" class="js-status">
                    @if ($canEdit && $b->isIncoming())
                        <span class="{{ $b->logStatusClass() }}">{{ $b->logStatusLabel() }}</span>@if ($b->planned_date)<br><span class="pb-muted">planned {{ $b->planned_date->format('M j') }}</span>@endif
                        <br><a class="pb-btn pb-btn-ghost pb-start-job" href="{{ route('production.index', ['start' => $b->batch_number]) }}">Start this job</a>
                    @elseif ($canEdit && $b->status !== 'void')
                        <form method="POST" action="{{ route('batch-log.status', $b) }}" class="pb-status-form">@csrf
                            <select name="log_status" class="pb-select pb-status-select lst-{{ $b->logStatus() }}" aria-label="Status of {{ $b->batch_number }}" onchange="this.form.submit()">
                                @foreach (\App\Models\Batch::STARTED_LOG_STATUSES as $k)<option value="{{ $k }}" class="lst-{{ $k }}" @selected($b->logStatus() === $k)>{{ \App\Models\Batch::LOG_STATUSES[$k] }}</option>@endforeach
                            </select><noscript><button class="pb-btn pb-btn-ghost">Save</button></noscript></form>
                    @else
                        <span class="{{ $b->logStatusClass() }}">{{ $b->logStatusLabel() }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="pb-muted">No batches match.</td></tr>
        @endforelse
        </tbody></table></div>
    <p class="pb-muted" style="margin-top:8px">Sample columns: {{ \App\Support\OcmSampling::INFO }}</p>
    {{ $batches->links() }}
</section>
@include('partials.tip-script')
@endsection
