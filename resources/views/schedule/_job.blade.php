{{-- One job inside a schedule cell (compact). --}}
<div class="sch-job">
    <b>{{ $job->title() }}</b>
    @if ($job->process_step)<span class="sch-job-meta">{{ $job->process_step }}</span>@endif
    @if ($job->batch)
        <span class="sch-batch"><span class="pb-bn">{{ $job->batch->batch_number }}</span>
            <span class="{{ $job->batch->logStatusClass() }}">{{ $job->batch->logStatusLabel() }}</span></span>
    @endif
</div>
