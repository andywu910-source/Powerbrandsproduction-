@extends('layouts.app')
@section('title', $batch->batch_number)
@section('page_title', $batch->batch_number)
@section('parent_title', 'Production')
@section('parent_url', route('production.index'))
@php
    use App\Support\Fmt;
    $u = auth()->user();
    $ro = $u->isViewer() || ! in_array($batch->status, ['in_process', 'in_qc'], true);
    $openNo = $open?->step_no;
    $ctx = compact('batch', 'signers', 'people', 'crew', 'stepSignatures');
@endphp

@section('content')
<section class="card">
    <div class="card-head">
        <span class="pb-bn pb-bn-lg">{{ $batch->batch_number }}</span>
        <span class="pill {{ Fmt::pill($batch->status) }}">{{ \App\Models\Batch::STATUSES[$batch->status] ?? $batch->status }}</span>
        @include('partials.rec.filing', ['record' => $batch])
        <span class="pb-spacer"></span>
        @if (\App\Support\Modules::enabled('batchlog'))<a class="pb-btn pb-btn-ghost" href="{{ route('batch-log.show', $batch) }}">Batch summary</a>@endif
    </div>
    <div class="pb-kv">
        <div><span>Product</span><b>{{ $batch->product->name }}</b></div>
        <div><span>Brand</span><b>{{ $batch->product->brand?->name ?? '—' }}</b></div>
        <div><span>Recipe (MMP)</span><b><a href="{{ route('plans.mmp', $batch->mmp) }}">{{ $batch->mmp->public_id }} v{{ $batch->mmp_version }}</a></b></div>
        <div><span>Units planned</span><b>{{ number_format($batch->target_units) }}</b></div>
        <div><span>Target potency</span><b>{{ $batch->target_potency ?? '—' }}</b></div>
        <div><span>Batch size</span><b>{{ $batch->batch_size_g ? Fmt::qty($batch->batch_size_g, 'g') : '—' }}</b></div>
        <div><span>Room / line</span><b>{{ $batch->room ? $batch->room->room_no.' · '.$batch->room->name : '—' }}{{ $batch->line ? ' / '.$batch->line : '' }}</b></div>
        <div><span>Started</span><b>{{ Fmt::dt($batch->started_at) }}</b></div>
        @if ($batch->batch_number_override_reason)<div><span>Batch number typed by admin</span><b>{{ $batch->batch_number_override_reason }}</b></div>@endif
    </div>
</section>

<nav class="pb-steps" aria-label="Batch steps">
    @foreach ($steps as $no => [$code, $title])
        @php $s = $batch->steps->firstWhere('step_no', $no); if ($no === 9 && ! config('pbp.qc_release_step')) $title = 'COA'; @endphp
        <a href="#step-{{ $no }}" class="st {{ $s?->status }}"><b>{{ $no }}</b>{{ $title }}
            <small>{{ $s?->status === 'complete' ? 'Done' : ($s?->status === 'open' ? 'Now' : 'Locked') }}</small></a>
    @endforeach
</nav>

@foreach ($steps as $no => [$code, $title, $blurb])
    @php $s = $batch->steps->firstWhere('step_no', $no); $isOpen = $s?->status === 'open';
        if ($no === 9 && ! config('pbp.qc_release_step')) { $title = 'COA'; $blurb = 'Attach the batch COA and record its result.'; } @endphp
    <section id="step-{{ $no }}" class="card pb-step-card is-{{ $s?->status }}">
        <div class="card-head"><span class="num">{{ $no }}</span><h3>{{ $title }}</h3>
            <span class="pill {{ Fmt::pill($s?->status ?? 'locked') }}">{{ ucfirst($s?->status ?? 'locked') }}</span>
            @if ($s?->completed_at)<span class="pb-muted">completed {{ Fmt::dt($s->completed_at) }}</span>@endif
        </div>
        <p class="pb-muted">{{ $blurb }}</p>
        @if ($s?->status === 'locked')
            <p class="pb-muted">Locked until step {{ $no - 1 }} is complete.</p>
        @else
            @include('production.steps.s'.$no, $ctx + ['s' => $s, 'isOpen' => $isOpen && ! $ro])
            @if ($s && $s->data && ! empty($s->data['staff_person_ids']))
                <p class="pb-muted">Done by: {{ $people->whereIn('id', $s->data['staff_person_ids'])->pluck('full_name')->implode(', ') ?: \App\Models\Person::whereIn('id', $s->data['staff_person_ids'])->pluck('full_name')->implode(', ') }}</p>
            @endif
            @if ($s && ! empty($s->data['notes']))<p class="pb-muted">Notes: {{ $s->data['notes'] }}</p>@endif
            @include('partials.rec.signatures', ['sigs' => $s->signatures])
            @if ($isOpen && ! $ro && ! in_array($no, [9], true))
                @include('partials.rec.problems', ['problems' => $problems])
                @if (! in_array($no, [7, 8], true))
                    @include('production.complete', $ctx + ['n' => $no, 'problems' => $problems])
                @endif
            @endif
        @endif
    </section>
@endforeach

<section class="card">
    <div class="card-head"><h3>Deviations</h3></div>
    <ul class="pb-list">
        @forelse ($batch->deviations as $d)
            <li><a href="{{ route('deviations.show', $d) }}">{{ $d->public_id }}</a> <b>{{ $d->title }}</b>
                <span class="pill {{ Fmt::pill($d->status) }}">{{ \App\Models\Deviation::STATUSES[$d->status] ?? $d->status }}</span>
                @if ($d->requires_qc_approval)<span class="pill blue">Needs QC</span>@endif</li>
        @empty
            <li class="pb-muted">None.</li>
        @endforelse
    </ul>
    @unless ($ro)
    <details class="pb-details"><summary>Open a deviation for this batch</summary>
        <form method="POST" action="{{ route('deviations.store') }}" class="pb-form">
            @csrf <input type="hidden" name="batch_id" value="{{ $batch->id }}">
            <div class="f-field"><label>Short title</label><input class="pb-input" name="title" required></div>
            <div class="f-field"><label>What happened</label><textarea name="description" rows="3" required></textarea></div>
            <label class="pb-check-inline"><input type="checkbox" name="active_ingredient" value="1"> Involves the active (cannabis) ingredient</label>
            <label class="pb-check-inline"><input type="checkbox" name="requires_qc_approval" value="1"> QC must approve before work continues</label>
            @include('partials.rec.sign', ['label' => 'Reported by'])
            <div><button class="btn-save" type="submit">Open deviation</button></div>
        </form></details>
    @endunless
</section>

<section class="card">
    <div class="card-head"><h3>Batch files</h3></div>
    @include('partials.rec.attachments', ['files' => $batch->attachments, 'upload' => $ro ? null : route('production.upload', $batch), 'kinds' => ['other' => 'Other document']])
    @include('partials.rec.revisions', ['revisions' => $batch->revisions])
    @if (! $ro)
        @include('partials.rec.void', ['action' => route('production.void', $batch), 'what' => 'batch'])
    @endif
</section>
@endsection
