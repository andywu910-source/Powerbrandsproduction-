@extends('layouts.app')
@section('title', 'Labels')
@section('page_title', 'Labels')
@php use App\Support\Fmt; @endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>Master box and case box labels (3 × 2 in)</h3></div>
    <p class="pb-muted">Pick a batch. The brand, product, size, type and batch number fill in by themselves. Choose <b>Master box</b> (the whole count: total, cases and count per case) or <b>Case box</b> (one small box: product, batch # and its count) on the next page, check the preview and print.</p>
    @include('labels.partials.picker', ['current' => $q ?? ''])
    @if (($batchCount ?? 1) === 0)
        <div class="pb-alert pb-alert-warn" id="noBatches">The Batch Log has no batches yet. Start a batch in Production, or make a label from a product below (you can type its barcode number on the label).</div>
    @endif
    @if ($hits !== null)
        <div class="pb-search-hits" id="batchHits">
            @if ($hits->isEmpty())
                <div class="pb-alert pb-alert-warn">No batch matches "{{ $q }}". Try part of the batch number, the product or the brand.</div>
            @else
                <p class="pb-muted">{{ $hits->count() }} batches match "{{ $q }}". Pick one:</p>
                <ul class="pb-list">@foreach ($hits as $b)
                    <li><a class="pb-bn" href="{{ route('labels.create', ['batch_id' => $b->id]) }}">{{ $b->batch_number }}</a>
                        <span>{{ trim(($b->product?->brand?->name ?? '').' '.($b->product?->name ?? '')) }}</span>
                        <span class="{{ $b->logStatusClass() }}">{{ $b->logStatusLabel() }}</span></li>
                @endforeach</ul>
            @endif
        </div>
    @endif
    <details class="pb-details" @if (($batchCount ?? 1) === 0) open @endif>
        <summary>No batch yet? Start from a product</summary>
        <form method="GET" action="{{ route('labels.create') }}" class="pb-inline-form">
            <div class="f-field"><label for="lp_product">Product</label><select id="lp_product" name="product" class="pb-select" required><option value="">—</option>
                @foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->brand?->name }} {{ $p->name }}</option>@endforeach</select></div>
            <button class="pb-btn pb-btn-ghost" type="submit">Make label</button>
        </form>
    </details>
</section>

<section class="card">
    <div class="card-head"><h3>Print log</h3><span class="pb-muted">Every print is kept, with the exact text printed.</span></div>
    <div class="table-wrap"><table class="pb-table pb-table-cards">
        <thead><tr><th>When</th><th>Who</th><th>Batch #</th><th>Type</th><th>Labels</th><th>Barcode</th><th></th></tr></thead>
        <tbody>
        @forelse ($prints as $p)
            <tr><td data-th="When">{{ Fmt::dt($p->created_at) }}</td><td data-th="Who">{{ $p->printer?->name }}</td>
                <td data-th="Batch #"><span class="pb-bn">{{ $p->fields['batch_number'] ?? '' }}</span></td>
                <td data-th="Type">{{ $p->typeLabel() }}</td>
                <td data-th="Labels">{{ $p->quantity }}</td>
                <td data-th="Barcode">{{ ($p->fields['barcode_value'] ?? '') !== '' && ! empty($p->fields['barcode_on']) ? $p->fields['barcode_value'] : '—' }}</td>
                <td>@if (! auth()->user()->isViewer())<a class="pb-btn pb-btn-ghost" href="{{ route('labels.create', ['from' => $p->id]) }}">Print again</a>@endif</td></tr>
        @empty
            <tr><td colspan="7" class="pb-muted">Nothing printed yet.</td></tr>
        @endforelse
        </tbody></table></div>
</section>
@endsection
