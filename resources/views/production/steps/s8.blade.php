@if (! empty($s->data['lab_name']))
    <div class="pb-kv"><div><span>Sampled</span><b>{{ $s->data['sampled_at'] ?? '' }}</b></div><div><span>Lab</span><b>{{ $s->data['lab_name'] }}</b></div>
        <div><span>Lab sampler</span><b>{{ $s->data['sampler_name'] ?? '' }}</b></div><div><span>Video ID</span><b>{{ $s->data['video_id'] ?? '' }}</b></div>
        <div><span>Retained samples</span><b>{{ $s->data['retained_qty'] ?? '' }} · {{ $s->data['retained_location'] ?? '' }}</b></div></div>
@endif
<h4>Chain of custody</h4>
@include('partials.rec.attachments', ['files' => \App\Models\Attachment::where('record_type', $s->getMorphClass())->where('record_id', $s->id)->get(),
    'upload' => $isOpen ? route('production.upload', $batch) : null, 'kinds' => ['coc' => 'Chain of custody (signed)']])
@if ($isOpen)
    @php $extra = view('production.steps.s8-fields')->render(); @endphp
    @include('production.complete', ['n' => 8, 'problems' => [], 'extra' => $extra])
@endif
