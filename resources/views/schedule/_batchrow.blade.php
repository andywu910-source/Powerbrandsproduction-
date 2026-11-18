{{-- One batch in Batch Log format (batch #, product, brand, units, status). $b = Batch, $remove = optional form action --}}
<tr>
    @isset($labNote)
        <td class="sch-labcheck">@if ($canEdit)<form method="POST" action="{{ route('schedule.note.save', $labNote) }}">@csrf<input type="hidden" name="lab_done" value="{{ $labNote->done ? 0 : 1 }}">
            <button class="sch-tick" aria-pressed="{{ $labNote->done ? 'true' : 'false' }}" aria-label="{{ $labNote->done ? 'Not at the lab yet' : 'Went to lab' }}: {{ $b->batch_number }}">{{ $labNote->done ? '✓' : '' }}</button></form>
            @else<span class="sch-tick ro">{{ $labNote->done ? '✓' : '' }}</span>@endif</td>
    @endisset
    <td><a class="pb-bn" href="{{ route('batch-log.show', $b) }}">{{ $b->batch_number }}</a>@if ($b->public_id)<br><span class="pb-muted">{{ $b->public_id }}</span>@endif</td>
    <td>{{ $b->product?->name }}</td>
    <td>{{ $b->product?->brand?->name }}</td>
    <td>{{ $b->target_units ? number_format($b->target_units) : '—' }}</td>
    <td><span class="{{ $b->logStatusClass() }}">{{ $b->logStatusLabel() }}</span>@if ($b->planned_date)<br><span class="pb-muted">planned {{ $b->planned_date->format('M j') }}</span>@endif</td>
    @isset($removeNote)
        <td>@if ($canEdit)<form method="POST" action="{{ route('schedule.note.save', $removeNote) }}">@csrf<input type="hidden" name="remove" value="1"><button class="sch-x" aria-label="Remove {{ $b->batch_number }}">×</button></form>@endif</td>
    @endisset
</tr>
