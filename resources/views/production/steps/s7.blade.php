@if ($batch->units_packed !== null)
    <div class="pb-kv"><div><span>Units packed</span><b>{{ number_format($batch->units_packed) }}</b></div><div><span>Yield</span><b>{{ $batch->yield_pct }}%</b></div>
        <div><span>Label version</span><b>{{ $s->data['label_version'] ?? '' }}</b></div>
        <div><span>Labels issued / used / destroyed / returned</span><b>{{ $s->data['labels_issued'] ?? '' }} / {{ $s->data['labels_used'] ?? '' }} / {{ $s->data['labels_destroyed'] ?? '' }} / {{ $s->data['labels_returned'] ?? '' }}</b></div></div>
@endif
@if ($isOpen)
    @php
        $openDevs = $batch->deviations->filter->allowsContinue();
        $extra = view('production.steps.s7-fields', ['batch' => $batch, 'devs' => $openDevs])->render();
    @endphp
    <p class="pb-muted">Yield must be {{ $batch->mmp->yield_min_pct }}–{{ $batch->mmp->yield_max_pct }}% of {{ number_format($batch->target_units) }} planned units. Labels issued must equal used + destroyed + returned.</p>
    @include('production.complete', ['n' => 7, 'problems' => [], 'extra' => $extra])
@endif
